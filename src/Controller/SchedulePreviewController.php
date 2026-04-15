<?php

declare(strict_types=1);

namespace Drupal\role_converter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Drupal\role_converter\Entity\ScheduledRole;
use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a dry-run preview of what a schedule would do if cron ran right now.
 */
class SchedulePreviewController extends ControllerBase {

  public function preview(ScheduledRole $scheduled_role): array {
    $service = \Drupal::service('role_converter.scheduled_role');
    $now = new DrupalDateTime('now');
    $is_active = $service->isScheduleActive($scheduled_role, $now);

    $role = Role::load($scheduled_role->getTargetRole());
    $role_label = $role ? $role->label() : $scheduled_role->getTargetRole();

    $build = [];

    $build['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['action-links']],
    ];
    $build['nav']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to Schedules'),
      '#url' => Url::fromRoute('entity.scheduled_role.collection'),
      '#attributes' => ['class' => ['button']],
    ];
    $build['nav']['edit'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit this Schedule'),
      '#url' => $scheduled_role->toUrl('edit-form'),
      '#attributes' => ['class' => ['button']],
    ];

    $status_class = $is_active ? 'messages--status' : 'messages--warning';
    $status_label = $is_active ? $this->t('ACTIVE') : $this->t('INACTIVE');
    $action = $scheduled_role->getAction();

    if ($is_active) {
      $would_do = $action === 'add'
        ? $this->t('ADD role "@role" to targeted users', ['@role' => $role_label])
        : $this->t('REMOVE role "@role" from targeted users', ['@role' => $role_label]);
    }
    else {
      $would_do = $action === 'add'
        ? $this->t('REMOVE role "@role" from targeted users (reverse of add, schedule inactive)', ['@role' => $role_label])
        : $this->t('ADD role "@role" back to targeted users (reverse of remove, schedule inactive)', ['@role' => $role_label]);
    }

    $build['status'] = [
      '#markup' => '<div class="' . $status_class . '"><p><strong>'
        . $this->t('Schedule: @label', ['@label' => $scheduled_role->label()])
        . '</strong> | ' . $this->t('Current status: @status', ['@status' => $status_label])
        . '</p><p>' . $this->t('If cron ran now: @action', ['@action' => $would_do])
        . '</p></div>',
    ];

    // Resolve affected users.
    $target_role_id = $scheduled_role->getTargetRole();
    $mode = $scheduled_role->getTargetingMode();
    $user_storage = $this->entityTypeManager()->getStorage('user');

    if ($mode === 'by_user') {
      $uids = $scheduled_role->getTargetingUsers();
    }
    else {
      $query = $user_storage->getQuery()
        ->condition('uid', [0, 1], 'NOT IN')
        ->accessCheck(FALSE);
      if ($mode === 'by_role') {
        $roles = $scheduled_role->getTargetingRoles();
        if (empty($roles)) {
          $uids = [];
        }
        else {
          $query->condition('roles', $roles, 'IN');
          $uids = array_values($query->execute());
        }
      }
      else {
        $uids = array_values($query->execute());
      }
    }

    $users = $user_storage->loadMultiple($uids);

    $would_change = 0;
    $already_correct = 0;
    $rows = [];

    foreach ($users as $user) {
      $has_role = $user->hasRole($target_role_id);
      $needs_change = FALSE;

      if ($is_active) {
        $needs_change = ($action === 'add' && !$has_role) || ($action === 'remove' && $has_role);
      }
      else {
        $needs_change = ($action === 'add' && $has_role) || ($action === 'remove' && !$has_role);
      }

      if ($needs_change) {
        $would_change++;
      }
      else {
        $already_correct++;
      }

      $rows[] = [
        $user->getDisplayName(),
        $user->getEmail() ?: '-',
        $has_role ? $this->t('Yes') : $this->t('No'),
        $needs_change ? $this->t('Will change') : $this->t('No change'),
      ];
    }

    $build['summary'] = [
      '#markup' => '<p><strong>' . $this->t('@total targeted users: @change would change, @same already correct.', [
        '@total' => count($users),
        '@change' => $would_change,
        '@same' => $already_correct,
      ]) . '</strong></p>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('User'),
        $this->t('Email'),
        $this->t('Has Role'),
        $this->t('Action'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No users targeted by this schedule.'),
    ];

    return $build;
  }

}
