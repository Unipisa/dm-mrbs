<?php

require "defaultincludes.inc";
require_once "functions_table.inc";

require('../vendor/autoload.php');

$provider = new \League\OAuth2\Client\Provider\GenericProvider([
    'clientId'                => getenv("MRBS_UNIPI_OAUTH_CLIENT_ID"),    // The client ID assigned to you by the provider
    'clientSecret'            => getenv("MRBS_UNIPI_OAUTH_SECRET"),    // The client password assigned to you by the provider
    'redirectUri'             => getenv('MRBS_URL_BASE') . '/oauth2_login.php',
    'urlAuthorize'            => getenv('MRBS_UNIPI_OAUTH_URL_AUTHORIZE'),
    'urlAccessToken'          => getenv('MRBS_UNIPI_OAUTH_URL_TOKEN'),
    'scopes'                  => 'openid profile email',
    'urlResourceOwnerDetails' => getenv('MRBS_UNIPI_OAUTH_URL_USERINFO')
]);

// If we don't have an authorization code then get one
if (!isset($_GET['code'])) {

    // Fetch the authorization URL from the provider; this returns the
    // urlAuthorize option and generates and applies any necessary parameters
    // (e.g. state).
    $authorizationUrl = $provider->getAuthorizationUrl();

    // Get the state generated for you and store it to the session.
    $_SESSION['oauth2state'] = $provider->getState();
    if (array_key_exists('redirect', $_GET))
        $_SESSION['oauth2redirect'] = $_GET['redirect'];
    else {
        $_SESSION['oauth2redirect'] = $_SERVER['HTTP_REFERER'] ?? getenv('MRBS_URL_BASE');
    } 

    // Redirect the user to the authorization URL.
    header('Location: ' . $authorizationUrl);
    exit;

// Check given state against previously stored one to mitigate CSRF attack
} elseif (empty($_GET['state']) || (isset($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state'])) {

    if (isset($_SESSION['oauth2state'])) {
        unset($_SESSION['oauth2state']);
    }

    exit('Invalid state');

} else {
    global $auth;

    try {

        // Try to get an access token using the authorization code grant.
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);
        
        $resourceOwner = $provider->getResourceOwner($accessToken);
        $data = $resourceOwner->toArray();

        $uid = explode('@', $data['sub'])[0];
        $matricola = isset($data['unipiMatricolaDipendente']) ? $data['unipiMatricolaDipendente'] : null;

        $allowed = false;

        if ($matricola) {
            try {
                $request = $provider->getAuthenticatedRequest(
                    'GET',
                    'https://api.unipi.it/unimapserv/3.0/getPersona?matr=' . $matricola,
                    $accessToken,
                    [ 'headers' => [ 'accept' => 'application/json' ] ]
                );
    
                $userdata = $provider->getParsedResponse($request);    
                $entries = $userdata['Entries'];

                if ($entries) {    
                    // We check if the user is in one of the allowed departments or 
                    // CDS, or in the list of allowed exceptions. 
                    foreach ($entries['Sede'] as $key => $sede) {
                        if (in_array($sede, $auth['valid_departments'])) {
                            $allowed = true;
                        }
                    }
                }
                // We may check among the assigned courses, but we don't do it
                // at the moment since it's not always clear what kind of data
                // may be inside $entries->Didattica->Corso.
            } catch (\Exception $e) {
                // If any exception was encountered we ignore the result of this step
                $allowed = false;
            }
        }        

        // DM-manager check
        $email = $data['email'];
        $dm_endpoint = "https://manage.dm.unipi.it/api/v0/public/staff?email=" . $email;
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $dm_endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $res = curl_exec($curl);
        curl_close($curl);
 
        $dm_data = json_decode($res);
        $staff = $dm_data->data->staff;
        if ($staff) {
            foreach ($staff as $s) {
                $role = $s->qualification;
                $isInternal = $s->isInternal; 
                $allowed |= ($isInternal && in_array($role, $auth['valid_roles']));
            }
        }

        // Additional exception
        $allowed |= in_array($uid, $auth['valid_uids']);

        if (getenv('MRBS_UNIPI_ALL_DEPARTMENTS') == 'true') {
            $allowed = true;
        }

        if ($allowed) {
            // Store the authentication data in the session
            $_SESSION['unipi_mail'] = $data['email'];
            $_SESSION['unipi_cn'] = (isset($data['given_name']) ? $data['given_name'] : '') .
                ' ' . (isset($data['family_name']) ? $data['family_name'] : '');
            $_SESSION['unipi_uid'] = $uid;
            $_SESSION['accessToken'] = $accessToken->getToken();
            $_SESSION['expiresToken'] = $accessToken->getExpires();
            $_SESSION['refreshToken'] = $accessToken->getRefreshToken();

            // Redirect the user to the home page
            session_write_close();
            header('Location: ' . (array_key_exists('oauth2redirect', $_SESSION) ? $_SESSION['oauth2redirect'] : '/'));
        }
        else {
            global $mrbs_admin_email;

            MRBS\print_header();

            ?>
              <h1><?= MRBS\get_vocab("accessdenied") ?></h1>
              <p>Questo account non è abilitato all'accesso al portale.</p>
              <p>Nel caso questo sia un errore, si prega di segnalarlo all'indirizzo 
                <a href="mailto:<?= $mrbs_admin_email ?>"><?= $mrbs_admin_email ?></a>
              </p>
              <p><a href="/">Torna all'home page</a></p>
            <?php exit(0);
        }

    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {

        // Failed to get the access token or user details.
        exit($e->getMessage());
    }

}

?>
