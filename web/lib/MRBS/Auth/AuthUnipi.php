<?php

namespace MRBS\Auth;

require('../vendor/autoload.php');

use \MRBS\User;

class AuthUnipi extends Auth
{
  private function getLdapConnection() {
    global $ldap_cn;
    global $ldap_pass;
    global $ldap_host;
    global $ldap_tls;
    global $ldap_v3;

    $ldap_uri = "ldap://$ldap_host";

    // We try to authenticate against the DM first
    putenv('LDAPTLS_REQCERT=never');
    $ds = ldap_connect($ldap_uri);

    if (! $ds) { 
      return false;
    }

    if ($ldap_v3)
      ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);

    if ($ldap_tls && !ldap_start_tls($ds)) {
      return false;
    }
    
    $r = ldap_bind($ds, $ldap_cn, $ldap_pass);

    return $ds;
  }

  private function getUserData($ds, $user) {
    global $ldap_base_dn;
    global $ldap_user_attrib;

    if (! $ds) {
      $ds = $this->getLdapConnection();
      if (! $ds) { 
        return false;
      }
    }

    $results = ldap_search($ds, $ldap_base_dn, $ldap_user_attrib . "=" . $user);
    $matches = ldap_get_entries($ds, $results);

    if (count($matches) == 0) {
      return false;
    }

    return $matches[0];
  }

  private function getUserFromLdap($ds, $user) {
    $data = $this->getUserData($ds, $user);

    if (! $data)
      return false;

    return $this->getUserFromData($user, $data);
  }

  private function getUserFromData($username, $data) {
    global $auth;

    $user = new User();

    $user->display_name = $data["cn"][0];
    $user->username = $username;

    if (array_key_exists('mail', $data)) {
      $user->email = $data['mail'][0];
    }
    else {
      $user->email = $this->getEmail($username);
    }
    
    $user->level = in_array($username, $auth["admin"]) ? 2 : 1;

    return $user;
  }

  /**
   * Retrieve the email for the user id using the UniPi API 'anagrafica'
   */
  public function getEmail($username) {
    $client_key = getenv('MRBS_UNIPIAPI_CLIENTKEY');
    $client_secret = getenv('MRBS_UNIPIAPI_CLIENTSECRET');

    /* Obtain an access token */
    $curl = curl_init('https://api.adm.unipi.it/token');

    /* Setup headers and post data */
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
      'Authorization: Basic ' . base64_encode($client_key . ":" . $client_secret)
    ]);

    $res = curl_exec($curl);
    $has_errors = curl_errno($curl) || curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200;
    curl_close($curl);

    $email = null;

    if (! $has_errors) {
      $data = json_decode($res);
      if ($data) {
        $token = $data->access_token;

        $curl = curl_init('https://api.adm.unipi.it:443/anagrafica/v2.0/id/' . $username);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
          'Accept: application/json',
          'Authorization: Bearer ' . $token
        ]);

        $res = curl_exec($curl);
        $has_errors = curl_errno($curl) || curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200;
        curl_close($curl);

        if (! $has_errors) {
          $data = json_decode($res);
          if ($data)
            $email = $data->email;
        }
      }
    }

    if (! $email) {
      $email = $username . '@tmp.unipi.it';
    }

    return $email;
  }

  /* authValidateUser($user, $pass)
   *
   * Checks if the specified username/password pair are valid
   *
   * $user  - The user name
   * $pass  - The password
   *
   * Returns:
   *   false    - The pair are invalid or do not exist
   *   string   - The validated username
   */
  public function validateUser($user, $pass)
  {
    global $auth;

    // Check if we do not have a username/password
    if(!isset($user) || !isset($pass))
    {
      return false;
    }

    $ds = $this->getLdapConnection();
    $data = $this->getUserData($ds, $user);
    
    $dn = $data["dn"];
    $r = @ldap_bind($ds, $dn, $pass);

    if (! $r) {
      return false;
    }
    else {
      return $this->checkPolicy($data) ? $user : false;
    }
  }

  public function checkPolicy($data) {
      global $auth;
      $dn = $data['dn'];

      // We enfore the policy for the username; either these are in one 
      // of the prescribed bases, or they have a specific uid. 
      $valid_bases = $auth["valid_bases"];
      $valid_uids = $auth["valid_uids"];

      if (in_array($data['uid'][0], $valid_uids)) {
        return true;
      }

      foreach ($valid_bases as $vb) {
        if (substr_compare($dn, $vb, -strlen($vb)) === 0) {
          return true;
        }
      }

      return false;
  }

  public function getUser($username) : ?User {
    global $auth;

    if (!isset($username) || ($username === ''))
    {
      return null;
    }

    if ($_SESSION['unipi_uid'])
    {    
      if ($username == $_SESSION['unipi_uid']) {
        $user = new User();
        $user->display_name = $_SESSION['unipi_cn'];
        $user->username = $_SESSION['unipi_uid'];
        $user->email = $_SESSION['unipi_mail'];
        $user->level = in_array($user->username, $auth["admin"]) ? 2 : 1;
      }
      else {
        $user = new User();
        $accessToken = $_SESSION['accessToken'];

        $user->username = $username;
        $user->level = in_array($user->username, $auth["admin"]) ? 2 : 1;
        $user->username = $username;

        // The API may or may not respond, as not all users that we care about are 
        // inside the unimapserv service; therefore, we wrap this into a try block 
        // and use some default values for display_name and email otherwise. 
        try {
          $accessToken = $_SESSION['accessToken'];
          $matricola = substr($username, 1);

          $client = new \GuzzleHttp\Client();
          $response = $client->request(
            'GET',
            'https://api.unipi.it/unimapserv/3.0/getPersona?matr=' . $matricola,
            [ 'headers' => [ 'accept' => 'application/json', 'Authorization' => 'Bearer ' . $accessToken ] ]
          );

          $userdata = json_decode($response->getBody());
          $entries = $userdata->Entries;

          $user->display_name = $entries->anagrafica->persona->nome . ' ' . $entries->anagrafica->persona->cognome;
          $user->username = $username;
          $user->email = $entries->anagrafica->persona->e_mail;
        } 
        catch (\Exception $e) {
          $user->display_name = $username;
          $user->email = "";
        }
      }
    }
    else
    {
      $ds = $this->getLdapConnection();
      $user = $this->getUserFromLdap($ds, $username);
    }

    return $user;
  }

  // Currently disabled because the users are a large number, and 
  // the interface is not able to handle them all. 
  public function getUsernames() {
    global $ldap_base_dn;

    $users = [];

    $ds = $this->getLdapConnection();

    foreach ([ "dm", "df", "di" ] as $ou) {
      $results = ldap_search($ds, "dc=$ou," . $ldap_base_dn, "(objectClass=posixAccount)", [ "cn", "uid" ]);

      $entry = ldap_first_entry($ds, $results);

      while ($entry) {
        $cn = ldap_get_values($ds, $entry, "cn");
        $uid = ldap_get_values($ds, $entry, "uid");
        $users[] = [
          "username" => $uid[0],
          "display_name" => $cn[0]
        ];
        $entry = ldap_next_entry($ds, $entry);
      }
    }

    return $users;
  }

}
