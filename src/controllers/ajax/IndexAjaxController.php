<?hh // strict

class IndexAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'team_id'     => FILTER_VALIDATE_INT,
        'teamname'    => FILTER_UNSAFE_RAW,
        'password'    => FILTER_UNSAFE_RAW,
        'logo'        => array(
          'filter'      => FILTER_VALIDATE_REGEXP,
          'options'     => array(
            'regexp'      => '/^[\w-]+$/'
          ),
        ),
        'token'        => array(
          'filter'      => FILTER_VALIDATE_REGEXP,
          'options'     => array(
            'regexp'      => '/^[\w]+$/'
          ),
        ),
        'names'       => FILTER_UNSAFE_RAW,
        'emails'      => FILTER_UNSAFE_RAW,
        'action'      => array(
          'filter'      => FILTER_VALIDATE_REGEXP,
          'options'     => array(
            'regexp'      => '/^[\w-]+$/'
          ),
        )
      )
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array(
      'register_team',
      'register_names',
      'login_team',
    );
  }

  <<__Override>>
  protected function handleAction(string $action, array<string, mixed> $params): string {
    switch ($action) {
    case 'none':
      return Utils::error_response('Invalid action', 'index');
    case 'register_team':
      return $this->registerTeam(
        must_have_string($params, 'teamname'),
        must_have_string($params, 'password'),
        strval(must_have_idx($params, 'token')),
        must_have_string($params, 'logo'),
        false,
        array(),
        array(),
      );
    case 'register_names':
      $names = json_decode(must_have_string($params, 'names'));
      $emails = json_decode(must_have_string($params, 'emails'));
      invariant(
        is_array($names) &&
        is_array($emails),
        'names and emails should be arrays',
      );

      return $this->registerTeam(
        must_have_string($params, 'teamname'),
        must_have_string($params, 'password'),
        strval(must_have_idx($params, 'token')),
        must_have_string($params, 'logo'),
        true,
        $names,
        $emails,
      );
    case 'login_team':
      $team_id = null;
      if (Configuration::get('login_select')->getValue() === '1') {
        $team_id = must_have_int($params, 'team_id');
      } else {
        $team_name = must_have_string($params, 'teamname');
        if (Team::teamExist($team_name)) {
          $team_id = Team::getTeamByName($team_name)->getId();
        } else {
          return Utils::error_response('Login failed', 'login');
        }
      }
      invariant(is_int($team_id), 'team_id should be an int');

      $password = must_have_string($params, 'password');

      // If we are here, login!
      return $this->loginTeam(
        $team_id,
        $password,
      );
    default:
      return Utils::error_response('Invalid action', 'index');
    }
  }

  private function registerTeam(
    string $teamname,
    string $password,
    ?string $token,
    string $logo,
    bool $register_names,
    array<string> $names,
    array<string> $emails,
  ): string {
    // Check if registration is enabled
    if (Configuration::get('registration')->getValue() === '0') {
      return Utils::error_response('Registration failed', 'registration');
    }

    // Check if tokenized registration is enabled
    if (Configuration::get('registration_type')->getValue() === '2') {
      // Check provided token
      if ($token === null || !Token::check($token)) {
        return Utils::error_response('Registration failed', 'registration');
      }
    }

    // Check logo
    $final_logo = $logo;
    if (!Logo::checkExists($final_logo)) {
      $final_logo = Logo::randomLogo();
    }

    // Check if team name is not empty or just spaces
    if (trim($teamname) === '') {
      return Utils::error_response('Registration failed', 'registration');
    }

    // Trim team name to 20 chars, to avoid breaking UI
    $shortname = substr($teamname, 0, 20);

    // Verify that this team name is not created yet
    if (!Team::teamExist($shortname)) {
      $password_hash = Team::generateHash($password);
      $team_id = Team::create($shortname, $password_hash, $final_logo);
      if ($team_id) {
        // Store team players data, if enabled
        if ($register_names) {
          for ($i=0; $i<count($names); $i++) {
            Team::addTeamData($names[$i], $emails[$i], $team_id);
          }
        }
        // If registration is tokenized, use the token
        if (Configuration::get('registration_type')->getValue() === '2') {
          invariant($token !== null, 'token should not be null');
          Token::use($token, $team_id);
        }
        // Login the team
        return $this->loginTeam($team_id, $password);
      } else {
        return Utils::error_response('Registration failed', 'registration');
      }
    } else {
      return Utils::error_response('Registration failed', 'registration');
    }
  }

  private function loginTeam(int $team_id, string $password): string {
    // Check if login is enabled
    if (Configuration::get('login')->getValue() === '0') {
      return Utils::error_response('Login failed', 'login');
    }

    // Verify credentials
    $team = Team::verifyCredentials($team_id, $password);

    if ($team) {
      SessionUtils::sessionStart();
      if (!SessionUtils::sessionActive()) {
        SessionUtils::sessionSet('team_id', strval($team->getId()));
        SessionUtils::sessionSet('name', $team->getName());
        SessionUtils::sessionSet('csrf_token', base64_encode(openssl_random_pseudo_bytes(16)));
        SessionUtils::sessionSet('IP', must_have_string(Utils::getSERVER(), 'REMOTE_ADDR'));
        if ($team->getAdmin()) {
          SessionUtils::sessionSet('admin', strval($team->getAdmin()));
        }
      }
      if ($team->getAdmin()) {
        $redirect = 'admin';
      } else {
        $redirect = 'game';
      }
      return Utils::ok_response('Login succesful', $redirect);
    } else {
      return Utils::error_response('Login failed', 'login');
    }
  }
}
