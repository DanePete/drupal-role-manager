<?php

declare(strict_types=1);

namespace Drupal\role_converter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;

/**
 * Role Manager form: add, remove, or convert roles with flexible targeting.
 *
 * Uses Batch API for large user sets to avoid timeouts.
 */
class RoleConverterForm extends FormBase {

  public function getFormId(): string {
    return 'role_converter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
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
    $form['nav']['scheduled'] = [
      '#type' => 'link',
      '#title' => $this->t('Scheduled Roles'),
      '#url' => Url::fromRoute('entity.scheduled_role.collection'),
      '#attributes' => ['class' => ['button']],
    ];
    $form['nav']['audit_log'] = [
      '#type' => 'link',
      '#title' => $this->t('Audit Log'),
      '#url' => Url::fromRoute('role_converter.audit_log'),
      '#attributes' => ['class' => ['button']],
    ];

    $form['tab_bar'] = [
      '#weight' => -90,
      '#markup' => '<div class="rc-tabs">'
        . '<button type="button" class="rc-tab rc-tab--active" data-rc-tab="rc-pane-rm-config">' . $this->t('Role Manager') . '</button>'
        . '<button type="button" class="rc-tab" data-rc-tab="rc-pane-rm-guide">' . $this->t('Help & Guide') . '</button>'
        . '</div>',
    ];

    $form['guide_pane'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'rc-pane-rm-guide', 'class' => ['rc-pane'], 'style' => 'display:none;'],
      '#weight' => -89,
    ];
    $form['guide_pane']['content'] = [
      '#markup' => $this->buildGuideContent(),
    ];

    $form['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
.rc-tabs { display:flex; gap:0; border-bottom:2px solid #0074bd; margin-bottom:1.5em; }
.rc-tab { padding:10px 24px; border:1px solid #ccc; border-bottom:none; background:#f5f5f5; cursor:pointer; font-size:14px; font-weight:600; color:#333; border-radius:4px 4px 0 0; margin-right:2px; }
.rc-tab:hover { background:#e8e8e8; }
.rc-tab.rc-tab--active { background:#fff; border-color:#0074bd; border-bottom:2px solid #fff; margin-bottom:-2px; color:#0074bd; }
.rc-pane { min-height:200px; }
',
      ],
      'role_converter_tab_styles',
    ];
    $form['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#value' => '
document.addEventListener("click", function(e) {
  var tab = e.target.closest(".rc-tab");
  if (!tab) return;
  var paneId = tab.getAttribute("data-rc-tab");
  tab.closest(".rc-tabs").querySelectorAll(".rc-tab").forEach(function(t) { t.classList.remove("rc-tab--active"); });
  tab.classList.add("rc-tab--active");
  document.querySelectorAll(".rc-pane").forEach(function(p) { p.style.display = "none"; });
  var target = document.getElementById(paneId);
  if (target) target.style.display = "";
});
',
      ],
      'role_converter_tab_script',
    ];

    $roles = $this->getRoleOptions();

    if (empty($roles)) {
      $form['message'] = [
        '#markup' => '<p>' . $this->t('No roles available.') . '</p>',
      ];
      return $form;
    }

    $form['operation'] = [
      '#type' => 'radios',
      '#title' => $this->t('Operation'),
      '#prefix' => '<div id="rc-pane-rm-config" class="rc-pane">',
      '#options' => [
        'add' => $this->t('Add role — grant a role to targeted users (keeps existing roles)'),
        'remove' => $this->t('Remove role — revoke a role from targeted users'),
        'convert' => $this->t('Convert roles — remove source role(s) and assign a new role'),
      ],
      '#required' => TRUE,
      '#default_value' => 'add',
    ];

    $form['target_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Target role'),
      '#description' => $this->t('The role to add or remove.'),
      '#options' => $roles,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select role -'),
      '#prefix' => '<div id="target-role-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['quick_add_role'] = [
      '#type' => 'details',
      '#title' => $this->t('Create a new role'),
      '#open' => FALSE,
    ];
    $form['quick_add_role']['note'] = [
      '#markup' => '<p class="description">' . $this->t('This creates a simple role for use with the Role Manager or Scheduled Roles. The new role will have <strong>no permissions</strong> by default. To add a role with specific permissions, please contact <strong>August Ash</strong>.') . '</p>',
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

    $form['source_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Source roles to remove'),
      '#description' => $this->t('Select one or more roles to remove during conversion.'),
      '#options' => $roles,
      '#states' => [
        'visible' => [
          ':input[name="operation"]' => ['value' => 'convert'],
        ],
        'required' => [
          ':input[name="operation"]' => ['value' => 'convert'],
        ],
      ],
    ];

    $form['targeting'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select users'),
      '#options' => [
        'by_role' => $this->t('By role — all users with specific role(s)'),
        'by_domain' => $this->t('By email domain — all users with a specific email domain'),
        'by_user' => $this->t('By user — select individual users'),
        'all' => $this->t('All users — every non-admin user'),
      ],
      '#required' => TRUE,
      '#default_value' => 'by_role',
    ];

    $form['filter_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Users with these roles'),
      '#description' => $this->t('Target all users who have at least one of the selected roles.'),
      '#options' => $roles,
      '#states' => [
        'visible' => [
          ':input[name="targeting"]' => ['value' => 'by_role'],
        ],
      ],
    ];

    $form['filter_domain'] = [
      '#type' => 'select',
      '#title' => $this->t('Email domain'),
      '#description' => $this->t('Target all users whose email matches this domain.'),
      '#options' => $this->getEmailDomains(),
      '#empty_option' => $this->t('- Select domain -'),
      '#states' => [
        'visible' => [
          ':input[name="targeting"]' => ['value' => 'by_domain'],
        ],
      ],
    ];

    $form['filter_status'] = [
      '#type' => 'select',
      '#title' => $this->t('User status'),
      '#description' => $this->t('Limit to active or blocked users, or include both.'),
      '#options' => [
        '' => $this->t('Active & Blocked'),
        '1' => $this->t('Active only'),
        '0' => $this->t('Blocked only'),
      ],
      '#default_value' => '',
    ];

    $form['users'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Users'),
      '#description' => $this->t('Type usernames to select individual users. Separate multiple with commas.'),
      '#target_type' => 'user',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
      '#tags' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="targeting"]' => ['value' => 'by_user'],
        ],
      ],
    ];

    // Preview / confirm step.
    $step = $form_state->get('step') ?? 'configure';

    if ($step === 'confirm') {
      $preview = $form_state->get('preview');
      $form['preview'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Preview — confirm before executing'),
        '#weight' => 100,
      ];
      $form['preview']['summary'] = [
        '#markup' => '<div class="messages messages--warning">'
        . '<p><strong>' . $preview['summary'] . '</strong></p>'
        . '<p>' . $this->t('@count user(s) will be affected.', ['@count' => $preview['count']]) . '</p>'
        . '</div>',
      ];

      if (!empty($preview['usernames'])) {
        $form['preview']['user_list'] = [
          '#markup' => '<details><summary>' . $this->t('Show affected users') . '</summary><p>' . implode(', ', $preview['usernames']) . '</p></details>',
        ];
      }

      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['confirm'] = [
        '#type' => 'submit',
        '#value' => $this->t('Execute'),
        '#button_type' => 'primary',
        '#submit' => ['::executeSubmit'],
        '#attributes' => [
          'onclick' => 'this.disabled=true; this.form.submit();',
        ],
      ];
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::backSubmit'],
        '#limit_validation_errors' => [],
        '#suffix' => '</div>',
      ];

      return $form;
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#suffix' => '</div>',
    ];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('step') === 'confirm') {
      return;
    }

    $operation = $form_state->getValue('operation');
    $targeting = $form_state->getValue('targeting');
    $target_role = $form_state->getValue('target_role');

    if ($operation === 'convert') {
      $source_roles = array_filter($form_state->getValue('source_roles') ?? []);
      if (empty($source_roles)) {
        $form_state->setErrorByName('source_roles', $this->t('Select at least one source role for conversion.'));
      }
      if (in_array($target_role, $source_roles)) {
        $form_state->setErrorByName('target_role', $this->t('The target role cannot also be a source role.'));
      }
    }

    if ($targeting === 'by_role') {
      $filter_roles = array_filter($form_state->getValue('filter_roles') ?? []);
      if (empty($filter_roles)) {
        $form_state->setErrorByName('filter_roles', $this->t('Select at least one role to target users.'));
      }
    }

    if ($targeting === 'by_domain') {
      $domain = $form_state->getValue('filter_domain');
      if (empty($domain)) {
        $form_state->setErrorByName('filter_domain', $this->t('Select an email domain.'));
      }
    }

    if ($targeting === 'by_user') {
      $users = $form_state->getValue('users');
      if (empty($users)) {
        $form_state->setErrorByName('users', $this->t('Select at least one user.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uids = $this->resolveTargetUsers($form_state);
    $operation = $form_state->getValue('operation');
    $target_role = $form_state->getValue('target_role');
    $target_label = Role::load($target_role)?->label() ?? $target_role;

    $summary = match ($operation) {
      'add' => $this->t('Add role "@role" to targeted users.', ['@role' => $target_label]),
      'remove' => $this->t('Remove role "@role" from targeted users.', ['@role' => $target_label]),
      'convert' => $this->t('Convert source roles to "@role".', ['@role' => $target_label]),
      default => '',
    };

    $usernames = [];
    if (count($uids) <= 100) {
      $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($uids);
      foreach ($users as $user) {
        $usernames[] = $user->getDisplayName();
      }
    }

    $form_state->set('step', 'confirm');
    $form_state->set('preview', [
      'summary' => $summary,
      'count' => count($uids),
      'usernames' => $usernames,
      'uids' => $uids,
    ]);
    $form_state->setRebuild();
  }

  /**
   * Execute via Batch API for large sets, inline for small ones.
   */
  public function executeSubmit(array &$form, FormStateInterface $form_state): void {
    $preview = $form_state->get('preview');
    $operation = $form_state->getValue('operation');
    $target_role = $form_state->getValue('target_role');
    $source_roles = array_values(array_filter($form_state->getValue('source_roles') ?? []));
    $uids = $preview['uids'];

    if (empty($uids)) {
      $this->messenger()->addWarning($this->t('No users to process.'));
      return;
    }

    $chunks = array_chunk($uids, 25);
    $operations = [];
    foreach ($chunks as $chunk) {
      $operations[] = [
        [static::class, 'batchProcess'],
        [$chunk, $operation, $target_role, $source_roles],
      ];
    }

    \batch_set([
      'title' => $this->t('Processing role changes...'),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
    ]);

    $form_state->set('step', 'configure');
  }

  /**
   * Batch callback: process a chunk of user IDs.
   */
  public static function batchProcess(array $uids, string $operation, string $target_role, array $source_roles, array &$context): void {
    if (!isset($context['results']['count'])) {
      $context['results']['count'] = 0;
      $context['results']['target_role'] = $target_role;
      $context['results']['operation'] = $operation;
    }

    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $users = $user_storage->loadMultiple($uids);

    foreach ($users as $user) {
      $changed = FALSE;

      if ($operation === 'add') {
        if (!$user->hasRole($target_role)) {
          $user->addRole($target_role);
          $changed = TRUE;
        }
      }
      elseif ($operation === 'remove') {
        if ($user->hasRole($target_role)) {
          $user->removeRole($target_role);
          $changed = TRUE;
        }
      }
      elseif ($operation === 'convert') {
        foreach ($source_roles as $rid) {
          if ($user->hasRole($rid)) {
            $user->removeRole($rid);
            $changed = TRUE;
          }
        }
        if (!$user->hasRole($target_role)) {
          $user->addRole($target_role);
          $changed = TRUE;
        }
      }

      if ($changed) {
        $user->save();
        $context['results']['count']++;
      }
    }

    $context['message'] = \t('Processed @count users...', ['@count' => count($uids)]);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if ($success) {
      $count = $results['count'] ?? 0;
      $target_role = $results['target_role'] ?? '';
      $operation = $results['operation'] ?? '';

      $role = Role::load($target_role);
      $role_label = $role ? $role->label() : $target_role;
      $op_label = match ($operation) {
        'add' => 'added',
        'remove' => 'removed',
        'convert' => 'converted',
        default => 'processed',
      };

      \Drupal::messenger()->addStatus(\t('Successfully @op role "@role" for @count user(s).', [
        '@op' => $op_label,
        '@role' => $role_label,
        '@count' => $count,
      ]));

      \Drupal::logger('role_converter')->info('Role manager: @op role "@role" for @count user(s) by @admin.', [
        '@op' => $op_label,
        '@role' => $role_label,
        '@count' => $count,
        '@admin' => \Drupal::currentUser()->getDisplayName(),
      ]);
    }
    else {
      \Drupal::messenger()->addError(\t('An error occurred during processing.'));
    }
  }

  public function backSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', 'configure');
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

    $this->messenger()->addStatus($this->t('Role "@name" created. It has no permissions — contact August Ash to configure permissions.', ['@name' => $name]));
    \Drupal::logger('role_converter')->info('Quick role created: "@name" (@id) by @admin.', [
      '@name' => $name,
      '@id' => $machine_name,
      '@admin' => \Drupal::currentUser()->getDisplayName(),
    ]);

    $form_state->setRebuild();
  }

  /**
   * @return int[]
   */
  private function resolveTargetUsers(FormStateInterface $form_state): array {
    $targeting = $form_state->getValue('targeting');
    $status_filter = $form_state->getValue('filter_status');
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');

    if ($targeting === 'by_role') {
      $filter_roles = array_filter($form_state->getValue('filter_roles') ?? []);
      if (empty($filter_roles)) {
        return [];
      }
      $query = $user_storage->getQuery()
        ->condition('roles', array_values($filter_roles), 'IN')
        ->condition('uid', [0, 1], 'NOT IN')
        ->accessCheck(FALSE);
      if ($status_filter !== '' && $status_filter !== NULL) {
        $query->condition('status', (int) $status_filter);
      }
      return array_values($query->execute());
    }

    if ($targeting === 'by_domain') {
      $domain = $form_state->getValue('filter_domain');
      if (empty($domain)) {
        return [];
      }
      $query = $user_storage->getQuery()
        ->condition('mail', '%@' . $domain, 'LIKE')
        ->condition('uid', [0, 1], 'NOT IN')
        ->accessCheck(FALSE);
      if ($status_filter !== '' && $status_filter !== NULL) {
        $query->condition('status', (int) $status_filter);
      }
      return array_values($query->execute());
    }

    if ($targeting === 'by_user') {
      $users = $form_state->getValue('users') ?? [];
      return array_map(fn($item) => (int) $item['target_id'], $users);
    }

    if ($targeting === 'all') {
      $query = $user_storage->getQuery()
        ->condition('uid', [0, 1], 'NOT IN')
        ->accessCheck(FALSE);
      if ($status_filter !== '' && $status_filter !== NULL) {
        $query->condition('status', (int) $status_filter);
      }
      return array_values($query->execute());
    }

    return [];
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

  /**
   * @return array<string, string>
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

  private function buildGuideContent(): string {
    $css = '
<style>
.rc-guide { font-size: 14px; line-height: 1.6; max-width: 860px; }
.rc-guide h2 { color: #0074bd; border-bottom: 2px solid #0074bd; padding-bottom: 6px; margin-top: 1.5em; }
.rc-guide .rc-card { background: #f7f9fc; border: 1px solid #d4dde6; border-radius: 6px; padding: 16px 20px; margin: 12px 0; }
.rc-guide .rc-card h4 { margin: 0 0 8px; color: #0074bd; }
.rc-guide .rc-flow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
.rc-guide .rc-flow-step { background: #0074bd; color: #fff; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 13px; white-space: nowrap; }
.rc-guide .rc-flow-arrow { font-size: 20px; color: #999; }
.rc-guide .rc-highlight { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 14px; margin: 12px 0; }
.rc-guide table { border-collapse: collapse; width: 100%; margin: 10px 0; }
.rc-guide th, .rc-guide td { border: 1px solid #d4dde6; padding: 8px 12px; text-align: left; }
.rc-guide th { background: #e9eff5; font-weight: 600; }
</style>';

    return $css . '<div class="rc-guide">'

    . '<h2>' . $this->t('What is the Role Manager?') . '</h2>'
    . '<p>' . $this->t('The Role Manager lets you change user roles in bulk — for many users at once. Instead of editing users one by one, you can add, remove, or swap roles for an entire group in a single action.') . '</p>'

    . '<div class="rc-highlight">'
    . '<strong>' . $this->t('What is a "role"?') . '</strong> '
    . $this->t('A role is a permission group. For example, "Editor" can edit content, "Member" can view certain pages. Users can have multiple roles at the same time.')
    . '</div>'

    . '<h2>' . $this->t('How to Use It') . '</h2>'
    . '<div class="rc-flow">'
    . '<span class="rc-flow-step">1. Pick an operation</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">2. Choose a role</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">3. Select users</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">4. Preview</span>'
    . '<span class="rc-flow-arrow">&#8594;</span>'
    . '<span class="rc-flow-step">5. Execute</span>'
    . '</div>'

    . '<h2>' . $this->t('Operations Explained') . '</h2>'

    . '<div class="rc-card"><h4>' . $this->t('Add Role') . '</h4>'
    . '<p>' . $this->t('Gives the selected role to all targeted users. Their existing roles stay the same — this just adds a new one on top.') . '</p>'
    . '<p><em>' . $this->t('Example: Give the "Member" role to all users with @example.com emails.') . '</em></p></div>'

    . '<div class="rc-card"><h4>' . $this->t('Remove Role') . '</h4>'
    . '<p>' . $this->t('Takes away the selected role from all targeted users. Their other roles are not affected.') . '</p>'
    . '<p><em>' . $this->t('Example: Remove the "Beta Tester" role from everyone after the beta period ends.') . '</em></p></div>'

    . '<div class="rc-card"><h4>' . $this->t('Convert Roles') . '</h4>'
    . '<p>' . $this->t('Removes one or more old roles and adds a new role — all in one step. This is useful when renaming or restructuring roles.') . '</p>'
    . '<p><em>' . $this->t('Example: Remove "Old Member" and add "New Member" to migrate everyone to the updated role.') . '</em></p></div>'

    . '<h2>' . $this->t('How to Select Users') . '</h2>'
    . '<table>'
    . '<tr><th>' . $this->t('Method') . '</th><th>' . $this->t('What it does') . '</th><th>' . $this->t('Best for') . '</th></tr>'
    . '<tr><td><strong>' . $this->t('By Role') . '</strong></td><td>' . $this->t('Selects all users who already have one of the roles you pick') . '</td><td>' . $this->t('Large groups') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('By Email Domain') . '</strong></td><td>' . $this->t('Selects all users whose email ends with a specific domain (e.g. @company.com)') . '</td><td>' . $this->t('Company-wide changes') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('By User') . '</strong></td><td>' . $this->t('Type individual names to pick specific people') . '</td><td>' . $this->t('Small, specific groups') . '</td></tr>'
    . '<tr><td><strong>' . $this->t('All Users') . '</strong></td><td>' . $this->t('Targets every user on the site (except the super-admin)') . '</td><td>' . $this->t('Site-wide changes') . '</td></tr>'
    . '</table>'

    . '<p>' . $this->t('You can also filter by <strong>user status</strong> (Active or Blocked) to narrow down who is affected.') . '</p>'

    . '<h2>' . $this->t('Safety Features') . '</h2>'
    . '<div class="rc-card"><h4>&#128270; ' . $this->t('Preview Before Executing') . '</h4>'
    . '<p>' . $this->t('After selecting an operation and users, you will see a <strong>preview</strong> showing exactly how many users will be affected and who they are. Nothing happens until you click <strong>Execute</strong>.') . '</p></div>'

    . '<div class="rc-card"><h4>&#128196; ' . $this->t('Audit Log') . '</h4>'
    . '<p>' . $this->t('Every change is recorded in the Audit Log — you can always see what was done, when, and by whom. Find it in the navigation buttons at the top of the page.') . '</p></div>'

    . '<div class="rc-card"><h4>&#128260; ' . $this->t('Creating New Roles') . '</h4>'
    . '<p>' . $this->t('You can create a simple new role using the "Create a new role" section below the role dropdown. <strong>Note:</strong> roles created here have no special permissions by default. To set up a role with specific access permissions, please contact <strong>August Ash</strong>.') . '</p></div>'

    . '<div class="rc-highlight" style="margin-top: 1.5em;">'
    . '<strong>' . $this->t('Need recurring changes?') . '</strong> '
    . $this->t('If you want roles to be added or removed automatically on a schedule (e.g. during business hours or on specific days), use <strong>Scheduled Roles</strong> instead — click the button in the navigation at the top.')
    . '</div>'

    . '</div>';
  }

}
