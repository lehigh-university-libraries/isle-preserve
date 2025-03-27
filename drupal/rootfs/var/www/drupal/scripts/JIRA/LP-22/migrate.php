<?php

use Drupal\Core\Session\UserSession;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

$userid = 21;
$account = User::load($userid);
$accountSwitcher = Drupal::service('account_switcher');
$userSession = new UserSession([
  'uid'   => $account->id(),
  'name'  => $account->getDisplayName(),
  'roles' => $account->getRoles(),
]);
$accountSwitcher->switchTo($userSession);

$nids = [305137, 334351, 383374, 329923, 329924, 329926, 329927, 329928, 329929, 329930, 329931, 329932, 329933, 329934, 329935, 329925, 329936, 329937, 329938, 329939, 329940, 329941, 329942, 329943, 329944, 329945, 329946, 329947, 329948, 329949, 329950, 329951, 329952, 329953, 329954, 329955, 329956, 329957, 329958, 329960, 329961, 329962, 329963, 329964, 329965, 329966, 329967, 329968, 329969, 329970, 329971, 329972, 329973, 329974, 329975, 329976, 329977, 329979, 329978, 329980, 329981, 329982, 329984, 329985, 329986, 329987, 329988, 329989, 329990, 329991, 329992, 329993, 329994, 329995, 329996, 329997, 329998, 329999, 330000, 330001, 330001, 330002, 330003, 330004, 330005, 330006, 330007, 330008, 330009, 330010, 330011, 330012, 330013, 330014, 330015, 330016, 330017, 330018, 330019, 330020, 330021, 330022, 330023, 330024, 330025, 383368, 383369, 383387, 383388, 383390, 383391, 383392, 383393, 383394, 383395, 383396, 383397, 383398, 383403, 383404, 383405, 383406, 383407, 383408, 383409, 383410, 383411, 383412, 383414, 383415, 384029, 384030, 384661, 384662, 384660, 384663, 335993, 335992, 333047, 332637, 333048, 334346, 334352, 334348, 334347, 383372, 334350, 333026, 383371, 384664, 384665, 333050, 383373];
foreach ($nids as $nid) {
  $node = Node::load($nid);

  // create a new child based off the parent
  // with minimal metadata
  $child = Node::create([
    'title' => $node->label(),
    'type' => $node->bundle(),
    'field_full_title' => $node->label(),
    'field_model' => $node->field_model->entity->id(),
    'field_member_of' => $node->id(),
    'status' => 1,
  ]);
  $child->save();

  // move parent media into child
  $mids = \Drupal::database()->query('SELECT entity_id FROM {media__field_media_of}
    WHERE field_media_of_target_id = :nid', [
      ':nid' => $node->id()
    ])->fetchCol();
  foreach ($mids as $mid) {
    $media = Media::load($mid);
    $media->set('field_media_of', $child->id());
    $media->save();
  }
}
