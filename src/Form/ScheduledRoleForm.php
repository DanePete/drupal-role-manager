<?php

declare(strict_types=1);

namespace Drupal\role_converter\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;

/**
 * Add/edit form for scheduled role config entities.
 */
class ScheduledRoleForm extends EntityForm {

  protected const USERS_PER_PAGE = 50;

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['backup_warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning"><strong>Important:</strong> Please communicate with August Ash before running role operations to take a current backup of the project. This message will be removed after we are fully confident everything is working after a couple of trial runs.</div>',
      '#weight' => -200,
    ];

    $form['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['action-links']],
      '#weight' => -100,
    ];
    $form['nav']['role_manager'] = [
      '#type' => 'link',
      '#title' => $this->t('Role Manager'),
      '#url' => Url::fromRoute('role_converter.form'),
      '#attributes' => ['class' => ['button']],
    ];
    $form['nav']['all_schedules'] = [
      '#type' => 'link',
      '#title' => $this->t('All Schedules'),
      '#url' => Url::fromRoute('entity.scheduled_role.collection'),
      '#attributes' => ['class' => ['button']],
    ];
    $form['nav']['audit_log'] = [
      '#type' => 'link',
      '#title' => $this->t('Audit Log'),
      '#url' => Url::fromRoute('role_converter.audit_log'),
      '#attributes' => ['class' => ['button']],
    ];

    /** @var \Drupal\role_converter\Entity\ScheduledRole $entity */
    $entity = $this->entity;
    if (!$entity->isNew()) {
      $form['nav']['history'] = [
        '#type' => 'link',
        '#title' => $this->t('History'),
        '#url' => Url::fromRoute('role_converter.schedule_history', ['scheduled_role' => $entity->id()]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-config-tab',
      '#weight' => -80,
    ];

    $form['config_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Schedule Configuration'),
      '#group' => 'tabs',
      '#weight' => 0,
    ];

    $form['guide_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Help & Guide'),
      '#group' => 'tabs',
      '#weight' => 10,
    ];
    $form['guide_tab']['content'] = [
      '#markup' => $this->buildGuideContent(),
    ];

    $roles = $this->getRoleOptions();

    if (!$entity->isNew() && $entity->status()) {
      $form['config_tab']['status_section'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Current Status'),
        '#weight' => -10,
      ];
      $form['config_tab']['status_section']['display'] = [
        '#markup' => $this->buildStatusDisplay($entity),
      ];
    }

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#required' => TRUE,
      '#group' => 'config_tab',
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\role_converter\Entity\ScheduledRole::load',
      ],
      '#disabled' => !$entity->isNew(),
      '#group' => 'config_tab',
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $entity->status(),
      '#group' => 'config_tab',
    ];

    $form['target_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#description' => $this->t('The role to add or remove on schedule.'),
      '#options' => $roles,
      '#default_value' => $entity->getTargetRole(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select role -'),
      '#group' => 'config_tab',
    ];

    $form['quick_add_role'] = [
      '#type' => 'details',
      '#title' => $this->t('Create a new role'),
      '#open' => FALSE,
      '#group' => 'config_tab',
    ];
    $form['quick_add_role']['note'] = [
      '#markup' => '<p class="description">' . $this->t('This creates a simple role for use with Scheduled Roles. The new role has the <strong>same permissions as an authenticated user</strong> — it is simply used to turn specific users on and off.') . '</p>',
    ];
    $form['quick_add_role']['new_role_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Role name'),
      '#size' => 40,
      '#maxlength' => 64,
    ];
    $form['quick_add_role']['create_role'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create role'),
      '#submit' => ['::createQuickRole'],
      '#validate' => ['::validateQuickRole'],
      '#limit_validation_errors' => [['new_role_name']],
    ];

    $form['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Action'),
      '#options' => [
        'add' => $this->t('Add role when schedule is active, remove when inactive'),
        'remove' => $this->t('Remove role when schedule is active, add back when inactive'),
      ],
      '#default_value' => $entity->getAction() ?: 'add',
      '#required' => TRUE,
      '#group' => 'config_tab',
    ];

    $form['targeting'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('User Targeting'),
      '#group' => 'config_tab',
    ];

    $form['targeting']['targeting_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Apply to'),
      '#options' => [
        'by_role' => $this->t('Users with specific roles'),
        'by_user' => $this->t('Individual users'),
        'all' => $this->t('All users (excluding admin)'),
      ],
      '#default_value' => $entity->getTargetingMode() ?: 'by_role',
      '#required' => TRUE,
    ];

    // By-role targeting.
    $form['targeting']['targeting_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#description' => $this->t('Users with any of these roles will be targeted.'),
      '#options' => $roles,
      '#default_value' => $entity->getTargetingRoles(),
      '#states' => [
        'visible' => [
          ':input[name="targeting_mode"]' => ['value' => 'by_role'],
        ],
      ],
    ];

    // By-user targeting: full user browser.
    $form['targeting']['user_browser'] = [
      '#type' => 'details',
      '#title' => $this->t('User Browser'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="targeting_mode"]' => ['value' => 'by_user'],
        ],
      ],
    ];

    // Initialize selected users in form state.
    if (!$form_state->has('selected_uids')) {
      $form_state->set('selected_uids', $entity->getTargetingUsers());
    }
    $selected_uids = $form_state->get('selected_uids');

    // Selected users display.
    $form['targeting']['user_browser']['selected_users'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'selected-users-wrapper'],
    ];

    if (!empty($selected_uids)) {
      $selected_accounts = $this->entityTypeManager->getStorage('user')->loadMultiple($selected_uids);

      $header = [
        $this->t('User'),
        $this->t('Email'),
        $this->t('Remove'),
      ];
      $rows = [];
      foreach ($selected_accounts as $account) {
        $rows[] = [
          $account->getDisplayName(),
          $account->getEmail() ?: '-',
          ['data' => ['#markup' => '<a href="#" class="role-converter-remove-user" data-uid="' . $account->id() . '">Remove</a>']],
        ];
      }

      $form['targeting']['user_browser']['selected_users']['heading'] = [
        '#markup' => '<h4>' . $this->t('Selected users (@count)', ['@count' => count($selected_uids)]) . '</h4>',
      ];
      $form['targeting']['user_browser']['selected_users']['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ];

      $form['targeting']['user_browser']['selected_users']['remove_uid'] = [
        '#type' => 'hidden',
        '#default_value' => '',
        '#attributes' => ['id' => 'remove-uid-field'],
      ];
      $form['targeting']['user_browser']['selected_users']['remove_btn'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove user'),
        '#submit' => ['::removeOneUser'],
        '#ajax' => [
          'callback' => '::ajaxRefreshFullBrowser',
          'wrapper' => 'full-user-browser-wrapper',
        ],
        '#limit_validation_errors' => [['remove_uid']],
        '#attributes' => ['id' => 'remove-user-btn', 'style' => 'display:none'],
      ];
      $form['targeting']['user_browser']['selected_users']['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#value' => '
            document.addEventListener("click", function(e) {
              if (e.target.classList.contains("role-converter-remove-user")) {
                e.preventDefault();
                document.getElementById("remove-uid-field").value = e.target.getAttribute("data-uid");
                document.getElementById("remove-user-btn").dispatchEvent(new Event("mousedown"));
              }
            });
          ',
        ],
        'role_converter_remove_user',
      ];

      $form['targeting']['user_browser']['selected_users']['clear'] = [
        '#type' => 'submit',
        '#value' => $this->t('Clear all selected'),
        '#submit' => ['::clearSelectedUsers'],
        '#ajax' => [
          'callback' => '::ajaxRefreshFullBrowser',
          'wrapper' => 'full-user-browser-wrapper',
        ],
        '#limit_validation_errors' => [],
      ];
    }
    else {
      $form['targeting']['user_browser']['selected_users']['empty'] = [
        '#markup' => '<p><em>' . $this->t('No users selected yet.') . '</em></p>',
      ];
    }

    // Filters.
    $form['targeting']['user_browser']['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['targeting']['user_browser']['filters']['user_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#size' => 30,
      '#placeholder' => $this->t('Username or email...'),
      '#default_value' => $form_state->getValue('user_search', ''),
    ];

    $form['targeting']['user_browser']['filters']['user_role_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#options' => ['' => $this->t('- Any role -')] + $roles,
      '#default_value' => $form_state->getValue('user_role_filter', ''),
    ];

    $form['targeting']['user_browser']['filters']['user_domain_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Email domain'),
      '#options' => ['' => $this->t('- Any domain -')] + $this->getEmailDomains(),
      '#default_value' => $form_state->getValue('user_domain_filter', ''),
    ];

    $form['targeting']['user_browser']['filters']['user_status_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        '' => $this->t('- Any status -'),
        '1' => $this->t('Active'),
        '0' => $this->t('Blocked'),
      ],
      '#default_value' => $form_state->getValue('user_status_filter', ''),
    ];

    $form['targeting']['user_browser']['filters']['filter_btn'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#submit' => ['::filterUsers'],
      '#ajax' => [
        'callback' => '::ajaxRefreshUserBrowser',
        'wrapper' => 'user-browser-ajax-wrapper',
      ],
      '#limit_validation_errors' => [
        ['user_search'],
        ['user_role_filter'],
        ['user_domain_filter'],
        ['user_status_filter'],
      ],
    ];

    // AJAX wrapper for the table + pagination.
    $form['targeting']['user_browser']['table_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'user-browser-ajax-wrapper'],
    ];

    // Build the user table.
    $page = (int) ($form_state->get('user_browser_page') ?? 0);
    $search = $form_state->getValue('user_search', '') ?: ($form_state->get('user_search_val') ?? '');
    $role_filter = $form_state->getValue('user_role_filter', '') ?: ($form_state->get('user_role_filter_val') ?? '');
    $domain_filter = $form_state->getValue('user_domain_filter', '') ?: ($form_state->get('user_domain_filter_val') ?? '');
    $status_filter = $form_state->getValue('user_status_filter', '') ?: ($form_state->get('user_status_filter_val') ?? '');

    $total = $this->countFilteredUsers($search, $role_filter, $domain_filter, $status_filter);
    $users = $this->loadFilteredUsers($search, $role_filter, $domain_filter, $status_filter, $page);

    $options = [];
    foreach ($users as $user) {
      $user_roles = array_diff($user->getRoles(), ['authenticated']);
      $options[$user->id()] = [
        'username' => $user->getDisplayName(),
        'email' => $user->getEmail() ?: '-',
        'roles' => $user_roles ? implode(', ', $user_roles) : $this->t('(none)'),
        'status' => $user->isActive() ? $this->t('Active') : $this->t('Blocked'),
      ];
    }

    $defaults = [];
    foreach ($selected_uids as $uid) {
      if (isset($options[$uid])) {
        $defaults[$uid] = $uid;
      }
    }

    $form['targeting']['user_browser']['table_wrapper']['user_table'] = [
      '#type' => 'tableselect',
      '#header' => [
        'username' => $this->t('Username'),
        'email' => $this->t('Email'),
        'roles' => $this->t('Roles'),
        'status' => $this->t('Status'),
      ],
      '#options' => $options,
      '#default_value' => $defaults,
      '#empty' => $this->t('No users found. Try adjusting your filters.'),
      '#js_select' => TRUE,
    ];

    // Pagination info + controls.
    $total_pages = max(1, (int) ceil($total / self::USERS_PER_PAGE));
    $form['targeting']['user_browser']['table_wrapper']['pager_info'] = [
      '#markup' => '<p>' . $this->t('Showing page @page of @total (@count users total)', [
        '@page' => $page + 1,
        '@total' => $total_pages,
        '@count' => $total,
      ]) . '</p>',
    ];

    if ($page > 0) {
      $form['targeting']['user_browser']['table_wrapper']['prev_page'] = [
        '#type' => 'submit',
        '#value' => $this->t('Previous page'),
        '#submit' => ['::prevPage'],
        '#ajax' => [
          'callback' => '::ajaxRefreshUserBrowser',
          'wrapper' => 'user-browser-ajax-wrapper',
        ],
        '#limit_validation_errors' => [],
      ];
    }

    if ($page < $total_pages - 1) {
      $form['targeting']['user_browser']['table_wrapper']['next_page'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next page'),
        '#submit' => ['::nextPage'],
        '#ajax' => [
          'callback' => '::ajaxRefreshUserBrowser',
          'wrapper' => 'user-browser-ajax-wrapper',
        ],
        '#limit_validation_errors' => [],
      ];
    }

    // "Add selected from table" button.
    $form['targeting']['user_browser']['table_wrapper']['add_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add checked users to selection'),
      '#submit' => ['::addSelectedUsers'],
      '#ajax' => [
        'callback' => '::ajaxRefreshFullBrowser',
        'wrapper' => 'full-user-browser-wrapper',
      ],
      '#limit_validation_errors' => [
        ['user_table'],
      ],
    ];

    // "Select all filtered" button — adds ALL users matching current filters.
    if ($total > 0) {
      $form['targeting']['user_browser']['table_wrapper']['add_all_filtered'] = [
        '#type' => 'submit',
        '#value' => $this->t('Select all @count filtered users', ['@count' => $total]),
        '#submit' => ['::addAllFilteredUsers'],
        '#ajax' => [
          'callback' => '::ajaxRefreshFullBrowser',
          'wrapper' => 'full-user-browser-wrapper',
        ],
        '#limit_validation_errors' => [
          ['user_search'],
          ['user_role_filter'],
          ['user_domain_filter'],
          ['user_status_filter'],
        ],
      ];
    }

    // Wrap the entire user_browser for full refresh (selected list + table).
    $form['targeting']['user_browser']['#prefix'] = '<div id="full-user-browser-wrapper">';
    $form['targeting']['user_browser']['#suffix'] = '</div>';

    $form['schedule'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Schedule'),
      '#group' => 'config_tab',
    ];

    $form['schedule']['recurrence'] = [
      '#type' => 'select',
      '#title' => $this->t('Recurrence'),
      '#options' => [
        'once' => $this->t('One-time'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
        'monthly' => $this->t('Monthly'),
      ],
      '#default_value' => $entity->getRecurrence() ?: 'daily',
      '#required' => TRUE,
    ];

    $form['schedule']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#description' => $this->t('Leave empty to start immediately.'),
      '#default_value' => $entity->getStartDate(),
    ];

    $form['schedule']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#description' => $this->t('Leave empty for no end date.'),
      '#default_value' => $entity->getEndDate(),
    ];

    $form['schedule']['start_time'] = [
      '#type' => 'time',
      '#title' => $this->t('Start time'),
      '#default_value' => $entity->getStartTime() ?: '00:00',
    ];

    $form['schedule']['end_time'] = [
      '#type' => 'time',
      '#title' => $this->t('End time'),
      '#description' => $this->t('Leave empty to keep the role until the next cycle.'),
      '#default_value' => $entity->getEndTime(),
    ];

    $form['schedule']['weekly_days'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Days of week'),
      '#options' => [
        0 => $this->t('Sunday'),
        1 => $this->t('Monday'),
        2 => $this->t('Tuesday'),
        3 => $this->t('Wednesday'),
        4 => $this->t('Thursday'),
        5 => $this->t('Friday'),
        6 => $this->t('Saturday'),
      ],
      '#default_value' => $entity->getWeeklyDays(),
      '#states' => [
        'visible' => [
          ':input[name="recurrence"]' => ['value' => 'weekly'],
        ],
      ],
    ];

    $form['schedule']['monthly_days'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Days of month'),
      '#description' => $this->t('Comma-separated day numbers 1-31 (e.g. 1,15,30).'),
      '#default_value' => $entity->getMonthlyDays() ? implode(',', $entity->getMonthlyDays()) : '',
      '#states' => [
        'visible' => [
          ':input[name="recurrence"]' => ['value' => 'monthly'],
        ],
      ],
    ];

    return $form;
  }

  // --- AJAX submit handlers ---

  public function filterUsers(array &$form, FormStateInterface $form_state): void {
    $form_state->set('user_browser_page', 0);
    $form_state->set('user_search_val', $form_state->getValue('user_search', ''));
    $form_state->set('user_role_filter_val', $form_state->getValue('user_role_filter', ''));
    $form_state->set('user_domain_filter_val', $form_state->getValue('user_domain_filter', ''));
    $form_state->set('user_status_filter_val', $form_state->getValue('user_status_filter', ''));
    $form_state->setRebuild();
  }

  public function prevPage(array &$form, FormStateInterface $form_state): void {
    $this->persistTableSelections($form_state);
    $page = (int) ($form_state->get('user_browser_page') ?? 0);
    $form_state->set('user_browser_page', max(0, $page - 1));
    $form_state->setRebuild();
  }

  public function nextPage(array &$form, FormStateInterface $form_state): void {
    $this->persistTableSelections($form_state);
    $page = (int) ($form_state->get('user_browser_page') ?? 0);
    $form_state->set('user_browser_page', $page + 1);
    $form_state->setRebuild();
  }

  public function addSelectedUsers(array &$form, FormStateInterface $form_state): void {
    $table_value = $form_state->getValue('user_table') ?? [];
    $newly_checked = array_filter($table_value);
    $selected = $form_state->get('selected_uids') ?? [];

    foreach (array_keys($newly_checked) as $uid) {
      $uid = (int) $uid;
      if (!in_array($uid, $selected)) {
        $selected[] = $uid;
      }
    }

    $form_state->set('selected_uids', $selected);
    $form_state->setRebuild();
  }

  public function clearSelectedUsers(array &$form, FormStateInterface $form_state): void {
    $form_state->set('selected_uids', []);
    $form_state->setRebuild();
  }

  public function removeOneUser(array &$form, FormStateInterface $form_state): void {
    $uid_to_remove = (int) $form_state->getValue('remove_uid');
    $selected = $form_state->get('selected_uids') ?? [];
    $selected = array_values(array_filter($selected, fn($uid) => (int) $uid !== $uid_to_remove));
    $form_state->set('selected_uids', $selected);
    $form_state->setRebuild();
  }

  public function validateQuickRole(array &$form, FormStateInterface $form_state): void {
    $name = trim($form_state->getValue('new_role_name') ?? '');
    if ($name === '') {
      $form_state->setErrorByName('new_role_name', $this->t('Enter a name for the new role.'));
      return;
    }
    $machine_name = preg_replace('/[^a-z0-9_]+/', '_', strtolower($name));
    if (Role::load($machine_name)) {
      $form_state->setErrorByName('new_role_name', $this->t('A role with the machine name "@id" already exists.', ['@id' => $machine_name]));
    }
  }

  public function createQuickRole(array &$form, FormStateInterface $form_state): void {
    $name = trim($form_state->getValue('new_role_name'));
    $machine_name = preg_replace('/[^a-z0-9_]+/', '_', strtolower($name));

    $role = Role::create([
      'id' => $machine_name,
      'label' => $name,
    ]);
    $role->save();

    $this->messenger()->addStatus($this->t('Role "@name" created. It has the same permissions as an authenticated user and is used to turn specific users on and off.', ['@name' => $name]));
    \Drupal::logger('role_converter')->info('Quick role created: "@name" (@id) by @admin.', [
      '@name' => $name,
      '@id' => $machine_name,
      '@admin' => \Drupal::currentUser()->getDisplayName(),
    ]);

    $form_state->setRebuild();
  }

  /**
   * Adds ALL users matching the current browser filters (not just current page).
   */
  public function addAllFilteredUsers(array &$form, FormStateInterface $form_state): void {
    $search = $form_state->getValue('user_search', '') ?: ($form_state->get('user_search_val') ?? '');
    $role_filter = $form_state->getValue('user_role_filter', '') ?: ($form_state->get('user_role_filter_val') ?? '');
    $domain_filter = $form_state->getValue('user_domain_filter', '') ?: ($form_state->get('user_domain_filter_val') ?? '');
    $status_filter = $form_state->getValue('user_status_filter', '') ?: ($form_state->get('user_status_filter_val') ?? '');

    $query = $this->buildUserQuery($search, $role_filter, $domain_filter, $status_filter);
    $all_uids = array_values($query->execute());

    $selected = $form_state->get('selected_uids') ?? [];
    foreach ($all_uids as $uid) {
      $uid = (int) $uid;
      if (!in_array($uid, $selected)) {
        $selected[] = $uid;
      }
    }
    $form_state->set('selected_uids', $selected);
    $form_state->setRebuild();
  }

  /**
   * Merges currently checked table rows into selected_uids on page change.
   */
  private function persistTableSelections(FormStateInterface $form_state): void {
    $table_value = $form_state->getValue('user_table') ?? [];
    $checked = array_filter($table_value);
    $selected = $form_state->get('selected_uids') ?? [];

    foreach (array_keys($checked) as $uid) {
      $uid = (int) $uid;
      if (!in_array($uid, $selected)) {
        $selected[] = $uid;
      }
    }

    $form_state->set('selected_uids', $selected);
  }

  // --- AJAX callbacks ---

  public function ajaxRefreshUserBrowser(array &$form, FormStateInterface $form_state): array {
    return $form['targeting']['user_browser']['table_wrapper'];
  }

  public function ajaxRefreshFullBrowser(array &$form, FormStateInterface $form_state): array {
    return $form['targeting']['user_browser'];
  }

  // --- Validation ---

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $targeting_mode = $form_state->getValue('targeting_mode');
    if ($targeting_mode === 'by_role') {
      $targeting_roles = array_filter($form_state->getValue('targeting_roles') ?? []);
      if (empty($targeting_roles)) {
        $form_state->setErrorByName('targeting_roles', $this->t('Select at least one role to target.'));
      }
    }

    if ($targeting_mode === 'by_user') {
      $selected = $form_state->get('selected_uids') ?? [];
      if (empty($selected)) {
        $form_state->setErrorByName('targeting_mode', $this->t('Select at least one user.'));
      }
    }

    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    if ($start_date && $end_date && $start_date > $end_date) {
      $form_state->setErrorByName('end_date', $this->t('End date must be after start date.'));
    }

    $start_time = $form_state->getValue('start_time');
    $end_time = $form_state->getValue('end_time');
    if ($start_time && $end_time && $start_time >= $end_time) {
      $form_state->setErrorByName('end_time', $this->t('End time must be after start time.'));
    }

    $recurrence = $form_state->getValue('recurrence');
    if ($recurrence === 'weekly') {
      $weekly_days = array_filter($form_state->getValue('weekly_days') ?? []);
      if (empty($weekly_days)) {
        $form_state->setErrorByName('weekly_days', $this->t('Select at least one day of the week.'));
      }
    }

    if ($recurrence === 'monthly') {
      $monthly_input = $form_state->getValue('monthly_days');
      if (empty($monthly_input)) {
        $form_state->setErrorByName('monthly_days', $this->t('Enter at least one day of the month.'));
      }
      else {
        $days = array_filter(array_map('trim', explode(',', $monthly_input)), fn($d) => is_numeric($d) && $d >= 1 && $d <= 31);
        if (empty($days)) {
          $form_state->setErrorByName('monthly_days', $this->t('Enter valid day numbers 1-31 separated by commas.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Prevents EntityForm from blindly mapping raw form values (strings from
   * textfields/checkboxes) onto entity properties. We handle the complex
   * fields explicitly in save().
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    $skip = [
      'weekly_days',
      'monthly_days',
      'targeting_roles',
      'targeting_users',
      'user_table',
      'user_search',
      'user_role_filter',
      'user_domain_filter',
      'user_status_filter',
      'new_role_name',
    ];
    $saved = [];
    foreach ($skip as $key) {
      $saved[$key] = $form_state->getValue($key);
      $form_state->unsetValue($key);
    }
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    foreach ($saved as $key => $value) {
      $form_state->setValue($key, $value);
    }
  }

  // --- Save ---

  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\role_converter\Entity\ScheduledRole $entity */
    $entity = $this->entity;

    $entity->set('targeting_mode', $form_state->getValue('targeting_mode'));
    $entity->set('targeting_roles', array_values(array_filter($form_state->getValue('targeting_roles') ?? [])));
    $entity->set('targeting_users', $form_state->get('selected_uids') ?? []);
    $entity->set('recurrence', $form_state->getValue('recurrence'));
    $entity->set('start_date', $form_state->getValue('start_date') ?: NULL);
    $entity->set('end_date', $form_state->getValue('end_date') ?: NULL);
    $entity->set('start_time', $form_state->getValue('start_time') ?: '00:00');
    $entity->set('end_time', $form_state->getValue('end_time') ?: NULL);

    $weekly_days = array_values(array_map('intval', array_filter($form_state->getValue('weekly_days') ?? [])));
    $entity->set('weekly_days', $weekly_days);

    $monthly_input = $form_state->getValue('monthly_days');
    if (!empty($monthly_input)) {
      $days = array_map('intval', array_filter(array_map('trim', explode(',', $monthly_input)), fn($d) => is_numeric($d) && $d >= 1 && $d <= 31));
      $entity->set('monthly_days', array_values($days));
    }
    else {
      $entity->set('monthly_days', []);
    }

    $status = $entity->save();

    $this->messenger()->addStatus($this->t('Scheduled role %label saved.', ['%label' => $entity->label()]));
    $form_state->setRedirectUrl($entity->toUrl('collection'));

    return $status;
  }

  // --- User query helpers ---

  private function loadFilteredUsers(string $search, string $role_filter, string $domain_filter, string $status_filter, int $page): array {
    $query = $this->buildUserQuery($search, $role_filter, $domain_filter, $status_filter);
    $query->range($page * self::USERS_PER_PAGE, self::USERS_PER_PAGE);
    $query->sort('name');
    $uids = $query->execute();

    return $uids ? $this->entityTypeManager->getStorage('user')->loadMultiple($uids) : [];
  }

  private function countFilteredUsers(string $search, string $role_filter, string $domain_filter, string $status_filter): int {
    $query = $this->buildUserQuery($search, $role_filter, $domain_filter, $status_filter);
    return (int) $query->count()->execute();
  }

  private function buildUserQuery(string $search, string $role_filter, string $domain_filter, string $status_filter = '') {
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->condition('uid', [0, 1], 'NOT IN')
      ->accessCheck(FALSE);

    if (!empty($search)) {
      $or = $query->orConditionGroup()
        ->condition('name', '%' . $search . '%', 'LIKE')
        ->condition('mail', '%' . $search . '%', 'LIKE');
      $query->condition($or);
    }

    if (!empty($role_filter)) {
      $query->condition('roles', $role_filter);
    }

    if (!empty($domain_filter)) {
      $query->condition('mail', '%@' . $domain_filter, 'LIKE');
    }

    if ($status_filter !== '' && $status_filter !== NULL) {
      $query->condition('status', (int) $status_filter);
    }

    return $query;
  }

  /**
   * Queries all unique email domains from the users table.
   *
   * @return array<string, string>
   *   Keyed by domain, value is "domain (count)".
   */
  private function getEmailDomains(): array {
    $database = \Drupal::database();
    $result = $database->query(
      "SELECT SUBSTRING_INDEX(mail, '@', -1) AS domain, COUNT(*) AS cnt
       FROM {users_field_data}
       WHERE uid > 1 AND mail IS NOT NULL AND mail != ''
       GROUP BY domain
       ORDER BY domain"
    );

    $options = [];
    foreach ($result as $row) {
      $options[$row->domain] = $row->domain . ' (' . $row->cnt . ')';
    }
    return $options;
  }

  /**
   * @return array<string, string>
   */
  private function getRoleOptions(): array {
    $roles = Role::loadMultiple();
    $options = [];
    foreach ($roles as $rid => $role) {
      if (!in_array($rid, ['anonymous', 'authenticated'])) {
        $options[$rid] = $role->label();
      }
    }
    return $options;
  }

  private function buildStatusDisplay($entity): string {
    $now = new DrupalDateTime('now');
    $is_active = \Drupal::service('role_converter.scheduled_role')->isScheduleActive($entity, $now);

    $role = Role::load($entity->getTargetRole());
    $role_label = $role ? $role->label() : $entity->getTargetRole();

    $status_class = $is_active ? 'messages--status' : 'messages--warning';
    $status_text = $is_active ? $this->t('ACTIVE') : $this->t('INACTIVE');

    $recurrence_label = match ($entity->getRecurrence()) {
      'once' => $this->t('One-time'),
      'daily' => $this->t('Daily'),
      'weekly' => $this->t('Weekly'),
      'monthly' => $this->t('Monthly'),
      default => $entity->getRecurrence(),
    };

    $output = '<div class="' . $status_class . '">';
    $output .= '<p><strong>' . $this->t('Role:') . '</strong> ' . $role_label;
    $output .= ' | <strong>' . $this->t('Status:') . '</strong> ' . $status_text;
    $output .= ' | <strong>' . $this->t('Schedule:') . '</strong> ' . $recurrence_label;

    $time_info = $entity->getStartTime() ?: '00:00';
    if ($entity->getEndTime()) {
      $time_info .= ' - ' . $entity->getEndTime();
    }
    $output .= ' (' . $time_info . ')';
    $output .= '</p></div>';

    return $output;
  }

  private function buildGuideContent(): string {
    $css = '
<style>
.rc-guide { font-size: 14px; line-height: 1.6; max-width: 860px; }
.rc-guide h2 { color: #0074bd; border-bottom: 2px solid #0074bd; padding-bottom: 6px; margin-top: 1.5em; }
.rc-guide h3 { color: #333; margin-top: 1.2em; }
.rc-guide .rc-card { background: #f7f9fc; border: 1px solid #d4dde6; border-radius: 6px; padding: 16px 20px; margin: 12px 0; }
.rc-guide .rc-card h4 { margin: 0 0 8px; color: #0074bd; }
.rc-guide .rc-example { background: #fff; border: 1px solid #ccc; border-radius: 4px; padding: 12px 16px; margin: 8px 0; font-family: monospace; font-size: 13px; }
.rc-guide .rc-flow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
.rc-guide .rc-flow-step { background: #0074bd; color: #fff; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 13px; white-space: nowrap; }
.rc-guide .rc-flow-arrow { font-size: 20px; color: #999; }
.rc-guide .rc-highlight { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 14px; margin: 12px 0; }
.rc-guide table { border-collapse: collapse; width: 100%; margin: 10px 0; }
.rc-guide th, .rc-guide td { border: 1px solid #d4dde6; padding: 8px 12px; text-align: left; }
.rc-guide th { background: #e9eff5; font-weight: 600; }
.rc-guide .rc-badge-active { background: #28a745; color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
.rc-guide .rc-badge-inactive { background: #999; color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
</style>';

    return $css . '<div class="rc-guide">'

    . '<h2>' . $this->t('What is a Scheduled Role?') . '</h2>'
    . '<p>' . $this->t('A <strong>Scheduled Role</strong> is a rule that automatically gives or takes away a permission group (called a "role") to a set of users on a schedule. The system checks this schedule regularly in the background — you do not need to do anything manually after setting it up.') . '</p>'

    . '<div class="rc-highlight">'
    . '<strong>' . $this->t('Think of it like a timer on a light switch:') . '</strong> '
    . $this->t('you set the time and who it applies to, and the system turns the role "on" or "off" automatically.')
    . '</div>'

    . '<h2>' . $this->t('Step-by-Step: How to Create a Schedule') . '</h2>'
    . '<div class="rc-flow">'
    . '<span class="rc-flow-step">1. Name it</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">2. Pick a role</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">3. Choose action</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">4. Select users</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">5. Set the schedule</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">6. Save</span>'
    . '</div>'

    . '<div class="rc-card"><h4>' . $this->t('Step 1: Give it a name') . '</h4>'
    . '<p>' . $this->t('Choose a descriptive name so you can find it later. Example: "Weekday Office Access" or "Monthly Report Role".') . '</p></div>'

    . '<div class="rc-card"><h4>' . $this->t('Step 2: Pick a role') . '</h4>'
    . '<p>' . $this->t('Select which role to give or take away. If you need a new role, expand the "Create a new role" section below the dropdown. <strong>Note:</strong> roles created here have the same permissions as an authenticated user — they are simply used to turn specific users on and off.') . '</p></div>'

    . '<div class="rc-card"><h4>' . $this->t('Step 3: Choose an action') . '</h4>'
    . '<table>'
    . '<tr><th>' . $this->t('Action') . '</th><th>' . $this->t('When schedule is ON') . '</th><th>' . $this->t('When schedule is OFF') . '</th></tr>'
    . '<tr><td><strong>' . $this->t('Add') . '</strong></td><td>' . $this->t('Users GET the role &#10003;') . '</td><td>' . $this->t('Role is REMOVED &#10007;') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('Remove') . '</strong></td><td>' . $this->t('Role is REMOVED &#10007;') . '</td><td>' . $this->t('Users GET the role back &#10003;') . '</td></tr>'
    . '</table>'
    . '<div class="rc-example">'
    . '<strong>' . $this->t('Example:') . '</strong> '
    . $this->t('"Add" the role "Office Hours Access" from 8 AM to 5 PM = users can access content during work hours, and their access is automatically removed after 5 PM.')
    . '</div>'
    . '</div>'

    . '<div class="rc-card"><h4>' . $this->t('Step 4: Select which users are affected') . '</h4>'
    . '<table>'
    . '<tr><th>' . $this->t('Method') . '</th><th>' . $this->t('Best for') . '</th></tr>'
    . '<tr><td><strong>' . $this->t('By role') . '</strong></td><td>' . $this->t('Large groups — e.g. "all editors" or "all members"') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('Individual users') . '</strong></td><td>' . $this->t('Specific people — use the search, filter by email domain, role, or status, then check the boxes') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('All users') . '</strong></td><td>' . $this->t('Everyone on the site (except the main admin account)') . '</td></tr>'
    . '</table></div>'

    . '<div class="rc-card"><h4>' . $this->t('Step 5: Set the schedule') . '</h4>'
    . '<table>'
    . '<tr><th>' . $this->t('Setting') . '</th><th>' . $this->t('What it means') . '</th></tr>'
    . '<tr><td><strong>' . $this->t('One-time / Daily') . '</strong></td><td>' . $this->t('Active every day during your chosen time window') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('Weekly') . '</strong></td><td>' . $this->t('Active only on the days of the week you check (e.g. Mon-Fri)') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('Monthly') . '</strong></td><td>' . $this->t('Active only on specific days of the month (e.g. the 1st and 15th)') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('Start / End date') . '</strong></td><td>' . $this->t('The date range when this schedule is in effect. Leave empty for "always".') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('Start / End time') . '</strong></td><td>' . $this->t('The daily time window. Example: 8:00 AM to 5:00 PM. Leave end time empty = active from start time until midnight.') . '</td></tr>'
    . '</table>'
    . '<div class="rc-example">'
    . '<strong>' . $this->t('Example:') . '</strong> '
    . $this->t('Weekly, Mon-Fri, 8:00-17:00, from Jan 1 to Dec 31 = the role is active during business hours on weekdays all year.')
    . '</div>'
    . '</div>'

    . '<h2>' . $this->t('After Saving') . '</h2>'
    . '<p>' . $this->t('Once saved, the schedule runs automatically in the background. You do not need to press any buttons — the system checks and applies changes on its own.') . '</p>'

    . '<h2>' . $this->t('Useful Tools') . '</h2>'
    . '<div class="rc-card"><h4>&#128269; ' . $this->t('Dry Run (Preview)') . '</h4>'
    . '<p>' . $this->t('On the schedule list page, click <strong>"Dry run"</strong> next to any schedule. This shows you exactly what <em>would</em> happen if the schedule ran right now — without actually making any changes. Great for verifying your settings before going live.') . '</p></div>'

    . '<div class="rc-card"><h4>&#128203; ' . $this->t('Clone (Duplicate)') . '</h4>'
    . '<p>' . $this->t('Click <strong>"Clone"</strong> on the schedule list to make a copy of an existing schedule. The copy starts as disabled so you can adjust it safely. Saves time when setting up similar schedules.') . '</p></div>'

    . '<div class="rc-card"><h4>&#128196; ' . $this->t('Audit Log') . '</h4>'
    . '<p>' . $this->t('Every role change — whether from a schedule or the Role Manager — is recorded in the <strong>Audit Log</strong>. You can see who was affected, what changed, and when. Find it in the navigation buttons at the top.') . '</p></div>'

    . '<div class="rc-card"><h4>&#9208; ' . $this->t('Enabled / Disabled') . '</h4>'
    . '<p>' . $this->t('The "Enabled" checkbox on the configuration tab lets you pause a schedule without deleting it. Disabled schedules are completely skipped — no changes are made to any users until you re-enable it.') . '</p></div>'

    . '<h2>' . $this->t('Quick Reference: Schedule Status') . '</h2>'
    . '<table>'
    . '<tr><th>' . $this->t('On the list page') . '</th><th>' . $this->t('What it means') . '</th></tr>'
    . '<tr><td><span class="rc-badge-active">Active</span></td><td>' . $this->t('The schedule is currently in its "on" window right now and is applying its action') . '</td></tr>'
    . '<tr><td><span class="rc-badge-inactive">Inactive</span></td><td>' . $this->t('The schedule exists but is currently outside its time window (the reverse action is applied)') . '</td></tr>'
    . '<tr><td>' . $this->t('Disabled') . '</td><td>' . $this->t('The schedule is turned off entirely and is not doing anything') . '</td></tr>'
    . '</table>'

    . '<div class="rc-highlight" style="margin-top: 1.5em;">'
    . '<strong>' . $this->t('Need help?') . '</strong> '
    . $this->t('If you need a role with specific permissions or have questions about how schedules interact with your site, please contact <strong>August Ash</strong>.')
    . '</div>'

    . '</div>';
  }

}
