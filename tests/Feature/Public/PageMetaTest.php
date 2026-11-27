<?php

use App\Support\PageMeta;

it('turns rich text into a plain, readable description', function () {
    $meta = PageMeta::make('OZ', '<p>Bandung&#039;s listeners.</p><p>From the <strong>Breakfast&nbsp;Club</strong> on.</p>')->toArray();

    expect($meta['description'])->toBe("Bandung's listeners. From the Breakfast Club on.");
});
