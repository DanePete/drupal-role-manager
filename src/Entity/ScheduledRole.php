<?php

declare(strict_types=1);

namespace Drupal\role_converter\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines a scheduled role assignment config entity.
 *
 * @ConfigEntityType(
 *   id = "scheduled_role",
 *   label = @Translation("Scheduled Role"),
 *   label_collection = @Translation("Scheduled Roles"),
 *   handlers = {
 *     "list_builder" = "Drupal\role_converter\ScheduledRoleListBuilder",
 *     "form" = {
 *       "add" = "Drupal\role_converter\Form\ScheduledRoleForm",
 *       "edit" = "Drupal\role_converter\Form\ScheduledRoleForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   config_prefix = "schedule",
 *   admin_permission = "manage role schedules",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "target_role",
 *     "action",
 *     "targeting_mode",
 *     "targeting_roles",
 *     "targeting_users",
 *     "recurrence",
 *     "start_date",
 *     "end_date",
 *     "start_time",
 *     "end_time",
 *     "weekly_days",
 *     "monthly_days"
 *   },
 *   links = {
 *     "add-form" = "/admin/people/scheduled-roles/add",
 *     "edit-form" = "/admin/people/scheduled-roles/{scheduled_role}/edit",
 *     "delete-form" = "/admin/people/scheduled-roles/{scheduled_role}/delete",
 *     "collection" = "/admin/people/scheduled-roles"
 *   }
 * )
 */
class ScheduledRole extends ConfigEntityBase {

  protected $id = '';
  protected $label = '';
  protected $status = TRUE;
  protected $target_role = '';
  protected $action = 'add';
  protected $targeting_mode = 'by_role';
  protected $targeting_roles = [];
  protected $targeting_users = [];
  protected $recurrence = 'daily';
  protected $start_date = NULL;
  protected $end_date = NULL;
  protected $start_time = '00:00';
  protected $end_time = NULL;
  protected $weekly_days = [];
  protected $monthly_days = [];

  public function getTargetRole(): string {
    return (string) $this->target_role;
  }

  public function getAction(): string {
    return (string) $this->action;
  }

  public function getTargetingMode(): string {
    return (string) $this->targeting_mode;
  }

  /**
   * @return string[]
   */
  public function getTargetingRoles(): array {
    return is_array($this->targeting_roles) ? $this->targeting_roles : [];
  }

  /**
   * @return int[]
   */
  public function getTargetingUsers(): array {
    return is_array($this->targeting_users) ? $this->targeting_users : [];
  }

  public function getRecurrence(): string {
    return (string) $this->recurrence;
  }

  public function getStartDate(): ?string {
    return $this->start_date ?: NULL;
  }

  public function getEndDate(): ?string {
    return $this->end_date ?: NULL;
  }

  public function getStartTime(): ?string {
    return $this->start_time ?: NULL;
  }

  public function getEndTime(): ?string {
    return $this->end_time ?: NULL;
  }

  /**
   * @return int[]
   */
  public function getWeeklyDays(): array {
    return is_array($this->weekly_days) ? $this->weekly_days : [];
  }

  /**
   * @return int[]
   */
  public function getMonthlyDays(): array {
    return is_array($this->monthly_days) ? $this->monthly_days : [];
  }

}
