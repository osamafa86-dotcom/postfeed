<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
// Stateless tokens: client just discards the JWT. Endpoint exists for symmetry.
api_ok(['logged_out' => true]);
