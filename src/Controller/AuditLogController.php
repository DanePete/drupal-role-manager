<?php

declare(strict_types=1);

namespace Drupal\role_converter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the role_converter audit log from watchdog entries.
 */
class AuditLogController extends ControllerBase {

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

  public function page(): array {
    $build = [];

    $build['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['action-links']],
    ];
    $build['nav']['role_manager'] = [
      '#type' => 'link',
      '#title' => $this->t('Role Manager'),
      '#url' => Url::fromRoute('role_converter.form'),
      '#attributes' => ['class' => ['button']],
    ];
    $build['nav']['schedules'] = [
      '#type' => 'link',
      '#title' => $this->t('Scheduled Roles'),
      '#url' => Url::fromRoute('entity.scheduled_role.collection'),
      '#attributes' => ['class' => ['button']],
    ];

    $header = [
      ['data' => $this->t('Date'), 'field' => 'w.timestamp', 'sort' => 'desc'],
      $this->t('Message'),
    ];

    $query = $this->database->select('watchdog', 'w')
      ->fields('w', ['timestamp', 'message', 'variables'])
      ->condition('type', 'role_converter')
      ->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->orderByHeader($header)
      ->limit(50);

    $results = $query->execute();
    $rows = [];

    foreach ($results as $row) {
      $variables = @unserialize($row->variables, ['allowed_classes' => FALSE]);
      $message = is_array($variables) ? strtr($row->message, $variables) : $row->message;

      $rows[] = [
        $this->dateFormatter->format($row->timestamp, 'short'),
        ['data' => ['#markup' => $message]],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No role operations have been logged yet.'),
    ];

    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

}
