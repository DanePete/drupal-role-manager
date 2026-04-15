<?php

declare(strict_types=1);

namespace Drupal\role_converter\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\role_converter\Entity\ScheduledRole;

/**
 * Processes all scheduled role config entities on cron.
 */
class ScheduledRoleService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Processes all enabled scheduled role entities.
   */
  public function processAll(): void {
    $schedules = $this->entityTypeManager
      ->getStorage('scheduled_role')
      ->loadByProperties(['status' => TRUE]);

    $now = new DrupalDateTime('now');

    foreach ($schedules as $schedule) {
      $this->processSchedule($schedule, $now);
    }
  }

  /**
   * Processes a single schedule entity.
   */
  public function processSchedule(ScheduledRole $schedule, DrupalDateTime $now): void {
    $target_role = $schedule->getTargetRole();
    if (empty($target_role)) {
      return;
    }

    $is_active = $this->isScheduleActive($schedule, $now);
    $uids = $this->resolveTargetUsers($schedule);

    if (empty($uids)) {
      return;
    }

    $action = $schedule->getAction();
    $user_storage = $this->entityTypeManager->getStorage('user');
    $users = $user_storage->loadMultiple($uids);
    $count = 0;

    foreach ($users as $user) {
      $changed = FALSE;

      if ($is_active) {
        if ($action === 'add' && !$user->hasRole($target_role)) {
          $user->addRole($target_role);
          $changed = TRUE;
        }
        elseif ($action === 'remove' && $user->hasRole($target_role)) {
          $user->removeRole($target_role);
          $changed = TRUE;
        }
      }
      else {
        // Reverse action when schedule is inactive.
        if ($action === 'add' && $user->hasRole($target_role)) {
          $user->removeRole($target_role);
          $changed = TRUE;
        }
        elseif ($action === 'remove' && !$user->hasRole($target_role)) {
          $user->addRole($target_role);
          $changed = TRUE;
        }
      }

      if ($changed) {
        $user->save();
        $count++;
      }
    }

    if ($count > 0) {
      $op = $is_active ? ($action === 'add' ? 'assigned' : 'removed') : ($action === 'add' ? 'removed' : 'restored');
      \Drupal::logger('role_converter')->info('Schedule "@label": @op role @role for @count user(s).', [
        '@label' => $schedule->label(),
        '@op' => $op,
        '@role' => $target_role,
        '@count' => $count,
      ]);
    }
  }

  /**
   * Determines whether a schedule is currently active.
   */
  public function isScheduleActive(ScheduledRole $schedule, DrupalDateTime $now): bool {
    $now_ts = $now->getTimestamp();

    // Date range check.
    $start_date = $schedule->getStartDate();
    if ($start_date) {
      $start_obj = new DrupalDateTime($start_date);
      if ($now_ts < $start_obj->getTimestamp()) {
        return FALSE;
      }
    }

    $end_date = $schedule->getEndDate();
    if ($end_date) {
      $end_obj = new DrupalDateTime($end_date . ' 23:59:59');
      if ($now_ts > $end_obj->getTimestamp()) {
        return FALSE;
      }
    }

    $current_time = $now->format('H:i');
    $start_time = $schedule->getStartTime() ?: '00:00';
    $end_time = $schedule->getEndTime();
    $recurrence = $schedule->getRecurrence();

    $in_time_window = $this->isInTimeWindow($current_time, $start_time, $end_time);

    return match ($recurrence) {
      'once', 'daily' => $in_time_window,
      'weekly' => in_array((int) $now->format('w'), $schedule->getWeeklyDays()) && $in_time_window,
      'monthly' => in_array((int) $now->format('j'), $schedule->getMonthlyDays()) && $in_time_window,
      default => FALSE,
    };
  }

  private function isInTimeWindow(string $current, string $start, ?string $end): bool {
    if ($end) {
      return $current >= $start && $current <= $end;
    }
    return $current >= $start;
  }

  /**
   * Resolves user IDs based on the schedule's targeting mode.
   *
   * @return int[]
   */
  private function resolveTargetUsers(ScheduledRole $schedule): array {
    $mode = $schedule->getTargetingMode();

    if ($mode === 'by_user') {
      return $schedule->getTargetingUsers();
    }

    $user_storage = $this->entityTypeManager->getStorage('user');
    $query = $user_storage->getQuery()
      ->condition('uid', [0, 1], 'NOT IN')
      ->accessCheck(FALSE);

    if ($mode === 'by_role') {
      $roles = $schedule->getTargetingRoles();
      if (empty($roles)) {
        return [];
      }
      $query->condition('roles', $roles, 'IN');
    }

    return array_values($query->execute());
  }

}
