<?php

declare(strict_types=1);

namespace Drupal\role_converter;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;

/**
 * List builder for scheduled role config entities.
 */
class ScheduledRoleListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['target_role'] = $this->t('Role');
    $header['action'] = $this->t('Action');
    $header['targeting'] = $this->t('Targets');
    $header['recurrence'] = $this->t('Schedule');
    $header['active_now'] = $this->t('Active Now');
    $header['status'] = $this->t('Enabled');
    return $header + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\role_converter\Entity\ScheduledRole $entity */
    $role = Role::load($entity->getTargetRole());
    $role_label = $role ? $role->label() : $entity->getTargetRole();

    $targeting = match ($entity->getTargetingMode()) {
      'all' => $this->t('All users'),
      'by_user' => $this->t('@count individual user(s)', ['@count' => count($entity->getTargetingUsers())]),
      default => $this->t('By role: @roles', ['@roles' => implode(', ', $this->resolveRoleLabels($entity->getTargetingRoles()))]),
    };

    $recurrence = match ($entity->getRecurrence()) {
      'once' => $this->t('One-time'),
      'daily' => $this->t('Daily'),
      'weekly' => $this->t('Weekly'),
      'monthly' => $this->t('Monthly'),
      default => $entity->getRecurrence(),
    };

    $date_range = '';
    if ($entity->getStartDate() || $entity->getEndDate()) {
      $date_range = ' (' . ($entity->getStartDate() ?: '...') . ' - ' . ($entity->getEndDate() ?: '...') . ')';
    }

    $now = new DrupalDateTime('now');
    $is_active = $entity->status() && \Drupal::service('role_converter.scheduled_role')->isScheduleActive($entity, $now);

    $row['label'] = $entity->label();
    $row['target_role'] = $role_label;
    $row['action'] = $entity->getAction() === 'add' ? $this->t('Add') : $this->t('Remove');
    $row['targeting'] = $targeting;
    $row['recurrence'] = $recurrence . $date_range;
    $row['active_now'] = [
      'data' => [
        '#markup' => $is_active
          ? '<span style="color: green; font-weight: bold;">&#9679; Active</span>'
          : '<span style="color: #999;">&#9675; Inactive</span>',
      ],
    ];
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);

    $operations['preview'] = [
      'title' => $this->t('Dry run'),
      'url' => Url::fromRoute('role_converter.schedule_preview', ['scheduled_role' => $entity->id()]),
      'weight' => 20,
    ];

    $operations['clone'] = [
      'title' => $this->t('Clone'),
      'url' => Url::fromRoute('role_converter.schedule_clone', ['scheduled_role' => $entity->id()]),
      'weight' => 30,
    ];

    $operations['history'] = [
      'title' => $this->t('History'),
      'url' => Url::fromRoute('role_converter.schedule_history', ['scheduled_role' => $entity->id()]),
      'weight' => 40,
    ];

    return $operations;
  }

  public function render(): array {
    $build['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['action-links']],
      '#weight' => -100,
    ];
    $build['nav']['role_manager'] = [
      '#type' => 'link',
      '#title' => $this->t('Role Manager'),
      '#url' => Url::fromRoute('role_converter.form'),
      '#attributes' => ['class' => ['button']],
    ];
    $build['nav']['audit_log'] = [
      '#type' => 'link',
      '#title' => $this->t('Audit Log'),
      '#url' => Url::fromRoute('role_converter.audit_log'),
      '#attributes' => ['class' => ['button']],
    ];
    $build += parent::render();
    return $build;
  }

  /**
   * @param string[] $role_ids
   * @return string[]
   */
  private function resolveRoleLabels(array $role_ids): array {
    $labels = [];
    foreach (Role::loadMultiple($role_ids) as $role) {
      $labels[] = $role->label();
    }
    return $labels;
  }

}
