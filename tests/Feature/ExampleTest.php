<?php

it('redirects the root to the typing page', function () {
    $this->get('/')->assertRedirect('/typing');
});
