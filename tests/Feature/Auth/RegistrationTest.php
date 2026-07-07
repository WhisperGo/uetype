<?php

test('the registration route redirects to the unified sign-in page', function () {
    $response = $this->get('/register');

    $response->assertRedirect(route('login'));
});

