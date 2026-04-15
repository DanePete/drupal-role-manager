<?php

declare(strict_types=1);

namespace Drupal\role_converter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\role_converter\Entity\ScheduledRole;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Clones a scheduled role config entity.
 */
class ScheduleCloneController extends ControllerBase {

  public function clone(ScheduledRole $scheduled_role): RedirectResponse {
    $storage = $this->entityTypeManager()->getStorage('scheduled_role');

    $base_id = $scheduled_role->id() . '_copy';
    $new_id = $base_id;
    $counter = 1;
    while ($storage->load($new_id)) {
      $new_id = $base_id . '_' . $counter++;
    }

    $clone = $scheduled_role->createDuplicate();
    $clone->set('id', $new_id);
    $clone->set('label', $scheduled_role->label() . ' (copy)');
    $clone->set('status', FALSE);
    $clone->save();

    $this->messenger()->addStatus($this->t('Cloned schedule as %label. It has been saved as disabled.', [
      '%label' => $clone->label(),
    ]));

    return new RedirectResponse($clone->toUrl('edit-form')->toString());
  }

}
