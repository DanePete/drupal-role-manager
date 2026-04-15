<?php

declare(strict_types=1);

namespace Drupal\role_converter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\role_converter\Entity\ScheduledRole;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Displays revision history and handles revert for scheduled role entities.
 */
class ScheduleHistoryController extends ControllerBase {

  protected const EXPORT_KEYS = [
    'id', 'label', 'status', 'target_role', 'action',
    'targeting_mode', 'targeting_roles', 'targeting_users',
    'recurrence', 'start_date', 'end_date', 'start_time', 'end_time',
    'weekly_days', 'monthly_days',
  ];

  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Displays the revision history table for a schedule.
   */
  public function history(ScheduledRole $scheduled_role): array {
    $build = [];

    $build['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['action-links']],
    ];
    $build['nav']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to edit'),
      '#url' => $scheduled_role->toUrl('edit-form'),
      '#attributes' => ['class' => ['button']],
    ];
    $build['nav']['all_schedules'] = [
      '#type' => 'link',
      '#title' => $this->t('All Schedules'),
      '#url' => Url::fromRoute('entity.scheduled_role.collection'),
      '#attributes' => ['class' => ['button']],
    ];
    $build['nav']['audit_log'] = [
      '#type' => 'link',
      '#title' => $this->t('Audit Log'),
      '#url' => Url::fromRoute('role_converter.audit_log'),
      '#attributes' => ['class' => ['button']],
    ];

    $header = [
      ['data' => $this->t('#'), 'field' => 'r.rid'],
      ['data' => $this->t('Date'), 'field' => 'r.timestamp', 'sort' => 'desc'],
      $this->t('Changed by'),
      $this->t('Summary'),
      $this->t('Operations'),
    ];

    $query = $this->database->select('role_converter_schedule_revisions', 'r')
      ->fields('r', ['rid', 'uid', 'timestamp', 'summary'])
      ->condition('schedule_id', $scheduled_role->id())
      ->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->orderByHeader($header)
      ->limit(30);

    $results = $query->execute();
    $rows = [];

    foreach ($results as $row) {
      $account = User::load($row->uid);
      $username = $account ? $account->getDisplayName() : $this->t('Unknown (uid @uid)', ['@uid' => $row->uid]);

      $revert_url = Url::fromRoute('role_converter.schedule_revert', [
        'scheduled_role' => $scheduled_role->id(),
        'rid' => $row->rid,
      ]);

      $rows[] = [
        $row->rid,
        $this->dateFormatter->format($row->timestamp, 'short'),
        $username,
        $row->summary ?: $this->t('Updated'),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Revert'),
            '#url' => $revert_url,
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No revisions recorded yet. Revisions are created each time this schedule is saved.'),
    ];

    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

  /**
   * Title callback for the history page.
   */
  public function historyTitle(ScheduledRole $scheduled_role): string {
    return (string) $this->t('History: @label', ['@label' => $scheduled_role->label()]);
  }

  /**
   * Reverts a schedule to a previous revision snapshot.
   */
  public function revert(ScheduledRole $scheduled_role, int $rid): RedirectResponse {
    $record = $this->database->select('role_converter_schedule_revisions', 'r')
      ->fields('r', ['data'])
      ->condition('rid', $rid)
      ->condition('schedule_id', $scheduled_role->id())
      ->execute()
      ->fetchField();

    if (!$record) {
      $this->messenger()->addError($this->t('Revision not found.'));
      return $this->redirectToHistory($scheduled_role);
    }

    $snapshot = json_decode($record, TRUE);
    if (!is_array($snapshot)) {
      $this->messenger()->addError($this->t('Could not decode revision data.'));
      return $this->redirectToHistory($scheduled_role);
    }

    foreach (self::EXPORT_KEYS as $key) {
      if ($key === 'id') {
        continue;
      }
      if (array_key_exists($key, $snapshot)) {
        $scheduled_role->set($key, $snapshot[$key]);
      }
    }

    $scheduled_role->save();

    $this->messenger()->addStatus($this->t('Schedule %label reverted to revision #@rid.', [
      '%label' => $scheduled_role->label(),
      '@rid' => $rid,
    ]));

    \Drupal::logger('role_converter')->info('Schedule "@id" reverted to revision #@rid by @user.', [
      '@id' => $scheduled_role->id(),
      '@rid' => $rid,
      '@user' => \Drupal::currentUser()->getDisplayName(),
    ]);

    return $this->redirectToHistory($scheduled_role);
  }

  private function redirectToHistory(ScheduledRole $scheduled_role): RedirectResponse {
    $url = Url::fromRoute('role_converter.schedule_history', [
      'scheduled_role' => $scheduled_role->id(),
    ])->toString();
    return new RedirectResponse($url);
  }

}
