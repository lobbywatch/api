<?php
declare(strict_types=1);

namespace App\Domain\IdentityAccess;

function user_access(string $rule) {
  switch ($rule) {
    case 'access lobbywatch general content':
      return true;
    case 'access lobbywatch advanced content':
    case 'access lobbywatch unpublished content':
    case 'access lobbywatch admin':
    default:
      return false;
  }
}
