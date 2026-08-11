<?php
$tempat = [['id' => 1]];
foreach ($tempat as $item): $uid = 't_' . $item['id']; ?>
    <div><?= $uid ?></div>
<?php endforeach; ?>
