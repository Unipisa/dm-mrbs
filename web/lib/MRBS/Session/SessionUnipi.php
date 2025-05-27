<?php
namespace MRBS\Session;

use MRBS\User;
use MRBS\Form\Form;

// Get user identity/password using the REMOTE_USER environment variable.
// Both identity and password equal the value of REMOTE_USER.
// 
// To use this session scheme, set in config.inc.php:
//
//                    $auth['session']  = 'remote_user';
//                    $auth['type'] = 'none';
//
// If you want to display a login link, set in config.inc.php:
//
//                    $auth['remote_user']['login_link'] = '/login/link.html';
//
// If you want to display a logout link, set in config.inc.php:
//
//                    $auth['remote_user']['logout_link'] = '/logout/link.html';


class SessionUnipi extends SessionWithLogin
{

  // User is expected to already be authenticated by the web server, so do nothing
  public function authGet(?string $target_url=null, ?string $returl=null, ?string $error=null, bool $raw=false) : void
  {
  }
  
  
  public function getCurrentUser() : ?User
  {
      if (isset($_SESSION['unipi_uid']) && $_SESSION['unipi_uid'])
      {
        if (isset($_SESSION['expiresToken']) && time() > intval($_SESSION['expiresToken'])) {
          return null;
        }
        else {
          return \MRBS\auth()->getUser($_SESSION['unipi_uid']);
        }
      }
      else
      {
          return null;
      }
  }
  
  
  public function getLogonFormParams() : null|array
  {
    if (getenv("MRBS_UNIPI_OAUTH_CLIENT_ID") != null)
    {
        return array(
            'action' => './oauth2_login.php',
            'method' => Form::METHOD_GET,
        );
    }
    else
    {
        return parent::getLogonFormParams();
    }
  }

  public function logoffUser() : void {
    // Unset the session variables
    session_unset();
    session_destroy();
    
    // Problems have been reported on Windows IIS with session data not being
    // written out without a call to session_write_close(). [Is this necessary
    // after session_destroy() ??]
    session_write_close();  
  }
}
