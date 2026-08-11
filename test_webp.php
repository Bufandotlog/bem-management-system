<?php
$img = imagecreatetruecolor(100, 100);
$res = imagewebp($img, 'test.webp', 75);
var_dump($res);
var_dump(filesize('test.webp'));
