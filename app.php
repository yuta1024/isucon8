<?php

use Slim\Http\Request;
use Slim\Http\Response;
use Psr\Container\ContainerInterface;

date_default_timezone_set('Asia/Tokyo');
define('TWIG_TEMPLATE', realpath(__DIR__).'/views');

$container = $app->getContainer();

$all_sheets = array(
    array('offset' =>   1, 'rank' => 'S', 'count' =>  50, 'price' => 5000),
    array('offset' =>  51, 'rank' => 'A', 'count' => 150, 'price' => 3000),
    array('offset' => 201, 'rank' => 'B', 'count' => 300, 'price' => 1000),
    array('offset' => 501, 'rank' => 'C', 'count' => 500, 'price' =>    0),
);

$all_sheets_by_rank = array(
    'S' => $all_sheets[0],
    'A' => $all_sheets[1],
    'B' => $all_sheets[2],
    'C' => $all_sheets[3],
);

function get_sheet_by_id($id) {
    global $all_sheets;

    foreach ($all_sheets as $s) {
        if ($id < $s['offset'] + $s['count']) {
            return $s;
        }
    }
}

$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig(TWIG_TEMPLATE);

    $baseUrl = function (\Slim\Http\Request $request): string {
      if ($request->hasHeader('Host')) {
        return $request->getUri()->getScheme().'://'.$request->getHeaderLine('Host');
      }

      return $request->getUri()->getBaseUrl();
    };

    $view->addExtension(new \Slim\Views\TwigExtension($container['router'], $baseUrl($container['request'])));

    return $view;
};

$login_required = function (Request $request, Response $response, callable $next): Response {
    $user = get_login_user($this);
    if (!$user) {
        return res_error($response, 'login_required', 401);
    }

    return $next($request, $response);
};

$fillin_user = function (Request $request, Response $response, callable $next): Response {
    $user = get_login_user($this);
    if ($user) {
        $this->view->offsetSet('user', $user);
    }

    return $next($request, $response);
};

$container['dbh'] = function (): PDOWrapper {
    return new PDOWrapper(new PDO(
        'mysql:host=172.17.119.2;port=3306;dbname=torb;charset=utf8mb4;',
        'isucon',
        'isucon',
        [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => true,
        ]
    ));
};

$app->get('/', function (Request $request, Response $response): Response {
    $events = array_map(function (array $event) { return sanitize_event($event); },
                        get_events($this->dbh));

    return $this->view->render($response, 'index.twig', [
        'events' => $events,
    ]);
})->add($fillin_user);

$app->get('/initialize', function (Request $request, Response $response): Response {
    exec('../../db/init.sh');

    return $response->withStatus(204);
});

$app->post('/api/users', function (Request $request, Response $response): Response {
    $nickname = $request->getParsedBodyParam('nickname');
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $user_id = null;


    try {
        $this->dbh->execute('INSERT INTO users (login_name, pass_hash, nickname) VALUES (?, ?, ?)', $login_name, hash('sha256', $password), $nickname);
        $user_id = $this->dbh->last_insert_id();

        setcookie('user_id', $user_id, time()+60*60*24*30, '/'); // 30days
        setcookie('login_name', $login_name, time()+60*60*24*30, '/'); // 30days
        setcookie('nickname', $nickname, time()+60*60*24*30, '/'); // 30days
    } catch (\Throwable $throwable) {
        return res_error($response, 'duplicated', 409);
    }

    return $response->withJson([
        'id' => $user_id,
        'nickname' => $nickname,
    ], 201, JSON_NUMERIC_CHECK);
});

/**
 * @param ContainerInterface $app
 *
 * @return bool|array
 */
function get_login_user(ContainerInterface $app)
{
    if(!isset($_COOKIE["user_id"])){
        return false;
    }
    return [
        'id' => (int)$_COOKIE["user_id"],
        'nickname' => $_COOKIE["nickname"],
    ];
}

$app->get('/api/users/{id}', function (Request $request, Response $response, array $args): Response {
    $user = $this->dbh->select_row('SELECT id, nickname FROM users WHERE id = ?', $args['id']);
    $user['id'] = (int) $user['id'];
    if (!$user || $user['id'] !== get_login_user($this)['id']) {
        return res_error($response, 'forbidden', 403);
    }

    $recent_reservations = function (ContainerInterface $app) use ($user) {
        $recent_reservations = [];

        $rows = $app->dbh->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id WHERE r.user_id = ? ORDER BY r.last_updated DESC LIMIT 5', $user['id']);
        foreach ($rows as $row) {
            $event = get_event($app->dbh, $row['event_id']);
            $price = $event['sheets'][$row['sheet_rank']]['price'];
            unset($event['sheets']);
            unset($event['total']);
            unset($event['remains']);

            $reservation = [
                'id' => $row['id'],
                'event' => $event,
                'sheet_rank' => $row['sheet_rank'],
                'sheet_num' => $row['sheet_num'],
                'price' => $price,
                'reserved_at' => (new \DateTime("{$row['reserved_at']}", new DateTimeZone('UTC')))->getTimestamp(),
            ];

            if ($row['canceled_at']) {
                $reservation['canceled_at'] = (new \DateTime("{$row['canceled_at']}", new DateTimeZone('UTC')))->getTimestamp();
            }

            array_push($recent_reservations, $reservation);
        }

        return $recent_reservations;
    };

    $user['recent_reservations'] = $recent_reservations($this);
    $user['total_price'] = $this->dbh->select_one('SELECT IFNULL(SUM(e.price + s.price), 0) FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled = 0', $user['id']);

    $recent_events = function (ContainerInterface $app) use ($user) {
        $recent_events = [];

        $rows = $app->dbh->select_all('SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(last_updated) DESC LIMIT 5', $user['id']);
        foreach ($rows as $row) {
            $event = get_event($app->dbh, $row['event_id']);
            foreach (array_keys($event['sheets']) as $rank) {
                unset($event['sheets'][$rank]['detail']);
            }
            array_push($recent_events, $event);
        }

        return $recent_events;
    };

    $user['recent_events'] = $recent_events($this);

    return $response->withJson($user, null, JSON_NUMERIC_CHECK);
})->add($login_required);

$app->post('/api/actions/login', function (Request $request, Response $response): Response {
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $user = $this->dbh->select_row('SELECT id, login_name, nickname, pass_hash FROM users WHERE login_name = ?', $login_name);
    $pass_hash = hash('sha256', $password); //$this->dbh->select_one('SELECT SHA2(?, 256)', $password);

    if (!$user || $pass_hash != $user['pass_hash']) {
        return res_error($response, 'authentication_failed', 401);
    }

    setcookie('user_id', $user['id'], time()+60*60*24*30, '/'); // 30days
    setcookie('login_name', $user['login_name'], time()+60*60*24*30, '/'); // 30days
    setcookie('nickname', $user['nickname'], time()+60*60*24*30, '/'); // 30days

    return $response->withJson($user, null, JSON_NUMERIC_CHECK);
});

$app->post('/api/actions/logout', function (Request $request, Response $response): Response {
    unset($_COOKIE['user_id']);
    setcookie('user_id', null, -1, '/');

    return $response->withStatus(204);
})->add($login_required);

$app->get('/api/events', function (Request $request, Response $response): Response {
    $events = array_map(function (array $event) { return sanitize_event($event); },
                        get_events($this->dbh));

    return $response->withJson($events, null, JSON_NUMERIC_CHECK);
});

$app->get('/api/events/{id}', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];

    $user = get_login_user($this);
    $event = get_event($this->dbh, $event_id, $user['id']);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'not_found', 404);
    }

    $event = sanitize_event($event);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
});

function get_events(PDOWrapper $dbh, ?callable $where = null): array
{
    if (null === $where) {
        $where = function (array $event) {
            return $event['public_fg'];
        };
    }

    $events = [];
    $event_ids = array_map(function (array $event) { return $event['id']; },
                           array_filter($dbh->select_all('SELECT * FROM events ORDER BY id ASC'), $where));

    foreach ($event_ids as $event_id) {
        $event = get_event($dbh, $event_id);

        foreach (array_keys($event['sheets']) as $rank) {
            unset($event['sheets'][$rank]['detail']);
        }

        array_push($events, $event);
    }

    return $events;
}

function get_event(PDOWrapper $dbh, int $event_id, ?int $login_user_id = null): array
{
    $event = $dbh->select_row('SELECT * FROM events WHERE id = ?', $event_id);

    if (!$event) {
        return [];
    }

    $event['id'] = (int) $event['id'];

    // zero fill
    $event['total'] = 0;
    $event['remains'] = 0;

    foreach (['S', 'A', 'B', 'C'] as $rank) {
        $event['sheets'][$rank]['total'] = 0;
        $event['sheets'][$rank]['remains'] = 0;
    }

    global $all_sheets;
    $sheets = $all_sheets;
    foreach ($sheets as $sheet) {
        $event['sheets'][$sheet['rank']]['price'] = $event['sheets'][$sheet['rank']]['price'] ?? $event['price'] + $sheet['price'];


        $reservations_select = $dbh->select_all('SELECT * FROM reservations WHERE event_id = ? AND sheet_id >= ? AND sheet_id < ? AND canceled = 0 GROUP BY event_id, sheet_id HAVING reserved_at = MIN(reserved_at)', $event['id'], $sheet['offset'], $sheet['offset'] + $sheet['count']);

        $reservations = array();

        foreach ($reservations_select as $r) {
            $reservations[(int)($r['sheet_id'])] = $r;
        }

        for ($sheet_id = $sheet['offset']; $sheet_id < $sheet['offset'] + $sheet['count']; ++$sheet_id) {
            ++$event['total'];
            ++$event['sheets'][$sheet['rank']]['total'];

            $s = $sheet;
            $s['num'] = $sheet_id - $sheet['offset'] + 1;

            if (array_key_exists($sheet_id, $reservations)) {
                $s['mine'] = $login_user_id && $reservations[$sheet_id]['user_id'] == $login_user_id;
                $s['reserved'] = true;
                $s['reserved_at'] = (new \DateTime("{$reservations[$sheet_id]['reserved_at']}", new DateTimeZone('UTC')))->getTimestamp();
            } else {
                ++$event['remains'];
                ++$event['sheets'][$s['rank']]['remains'];
            }

            $rank = $s['rank'];
            unset($s['price']);
            unset($s['rank']);

            if (false === isset($event['sheets'][$rank]['detail'])) {
                $event['sheets'][$rank]['detail'] = [];
            }

            array_push($event['sheets'][$rank]['detail'], $s);
        }
    }

    $event['public'] = $event['public_fg'] ? true : false;
    $event['closed'] = $event['closed_fg'] ? true : false;

    unset($event['public_fg']);
    unset($event['closed_fg']);

    return $event;
}

function sanitize_event(array $event): array
{
    unset($event['price']);
    unset($event['public']);
    unset($event['closed']);

    return $event;
}

$app->post('/api/events/{id}/actions/reserve', function (Request $request, Response $response, array $args): Response {
        $event_id = $args['id'];
        $rank = $request->getParsedBodyParam('sheet_rank');

    $user = get_login_user($this);
    $event = get_event($this->dbh, $event_id, $user['id']);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'invalid_event', 404);
    }

    if (!validate_rank($this->dbh, $rank)) {
        return res_error($response, 'invalid_rank', 400);
    }

    $sheet = null;
    $reservation_id = null;

    global $all_sheets_by_rank;
    $sheet = $all_sheets_by_rank[$rank];


    $current_time = (new DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    try {
        $this->dbh->execute('INSERT INTO reservations (event_id, sheet_id, user_id, reserved_at, last_updated, canceled) VALUES (?, (SELECT id FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations AS r WHERE event_id = ? AND canceled = 0) AND `rank` = ? ORDER BY RAND() LIMIT 1), ?, ?, ?, 0)',
                                     $event['id'], $event['id'], $rank, $user['id'], $current_time, $current_time
                            );
        $reservation_id = (int) $this->dbh->last_insert_id();
    } catch (\Exception $e) {
        return res_error($response, 'sold_out', 409);
    }

    $success = $this->dbh->select_row('SELECT sheet_id FROM reservations WHERE id = ?', $reservation_id);

    $sheet_id = $success['sheet_id'];

    return $response->withJson([
        'id' => $reservation_id,
        'sheet_rank' => $rank,
        'sheet_num' => $sheet_id - $sheet['offset'] + 1,
    ], 202, JSON_NUMERIC_CHECK);
})->add($login_required);

$app->delete('/api/events/{id}/sheets/{ranks}/{num}/reservation', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $rank = $args['ranks'];
    $num = $args['num'] - 1;

    $user = get_login_user($this);
    $event = get_event($this->dbh, $event_id, $user['id']);

    if (empty($event) || !$event['public']) {
        return res_error($response, 'invalid_event', 404);
    }

    if (!validate_rank($this->dbh, $rank)) {
        return res_error($response, 'invalid_rank', 404);
    }

    global $all_sheets_by_rank;
    $sheet_by_rank = $all_sheets_by_rank[$rank];

    if ($num < 0 ||  $sheet_by_rank['count'] <= $num) {
        return res_error($response, 'invalid_sheet', 404);
    }

    $current_time = (new DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $this->dbh->beginTransaction();
    try {
        $reservation = $this->dbh->select_row('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled = 0 GROUP BY event_id HAVING reserved_at = MIN(reserved_at)', $event['id'], $num + $sheet_by_rank['offset']);
        if (!$reservation) {
            $this->dbh->rollback();

            return res_error($response, 'not_reserved', 400);
        }

        if ($reservation['user_id'] != $user['id']) {
            $this->dbh->rollback();

            return res_error($response, 'not_permitted', 403);
        }

        $this->dbh->execute('UPDATE reservations SET canceled_at = ?, last_updated = ?, canceled = 1 WHERE id = ?', $current_time, $current_time, $reservation['id']);
        $this->dbh->commit();
    } catch (\Exception $e) {
        $this->dbh->rollback();

        return res_error($response);
    }

    return $response->withStatus(204);
})->add($login_required);

function validate_rank(PDOWrapper $dbh, $rank)
{
    if ($rank == 'S') return true;
    if ($rank == 'A') return true;
    if ($rank == 'B') return true;
    if ($rank == 'C') return true;

    return false;
}

$admin_login_required = function (Request $request, Response $response, callable $next): Response {
    $administrator = get_login_administrator($this);
    if (!$administrator) {
        return res_error($response, 'admin_login_required', 401);
    }

    return $next($request, $response);
};

$fillin_administrator = function (Request $request, Response $response, callable $next): Response {
    $administrator = get_login_administrator($this);
    if ($administrator) {
        $this->view->offsetSet('administrator', $administrator);
    }

    return $next($request, $response);
};

$app->get('/admin/', function (Request $request, Response $response) {
    $events = get_events($this->dbh, function ($event) { return $event; });

    return $this->view->render($response, 'admin.twig', [
        'events' => $events,
    ]);
})->add($fillin_administrator);

$app->post('/admin/api/actions/login', function (Request $request, Response $response): Response {
    $login_name = $request->getParsedBodyParam('login_name');
    $password = $request->getParsedBodyParam('password');

    $administrator = $this->dbh->select_row('SELECT * FROM administrators WHERE login_name = ?', $login_name);
    $pass_hash = hash('sha256', $password); //$this->dbh->select_one('SELECT SHA2(?, 256)', $password);

    if (!$administrator || $pass_hash != $administrator['pass_hash']) {
        return res_error($response, 'authentication_failed', 401);
    }

    setcookie('administrator_id', $administrator['id'], time()+60*60*24*30, '/');
    setcookie('admin_login_name', $administrator['login_name'], time()+60*60*24*30., '/');
    setcookie('admin_nickname', $administrator['nickname'], time()+60*60*24*30, '/');

    return $response->withJson($administrator, null, JSON_NUMERIC_CHECK);
});

$app->post('/admin/api/actions/logout', function (Request $request, Response $response): Response {
    unset($_COOKIE['administrator_id']);
    setcookie('administrator_id', null, -1, '/');

    return $response->withStatus(204);
})->add($admin_login_required);

/**
 * @param ContainerInterface $app*
 *
 * @return bool|array
 */
function get_login_administrator(ContainerInterface $app)
{
    if (!isset($_COOKIE['administrator_id'])) {
        return false;
    }

    return [
        'id' => (int)$_COOKIE['administrator_id'],
        'nickname' => $_COOKIE['admin_nickname'],
    ];
}

$app->get('/admin/api/events', function (Request $request, Response $response): Response {
    $events = get_events($this->dbh, function ($event) { return $event; });

    return $response->withJson($events, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->post('/admin/api/events', function (Request $request, Response $response): Response {
    $title = $request->getParsedBodyParam('title');
    $public = $request->getParsedBodyParam('public') ? 1 : 0;
    $price = $request->getParsedBodyParam('price');

    $event_id = null;

    try {
        $this->dbh->execute('INSERT INTO events (title, public_fg, closed_fg, price) VALUES (?, ?, 0, ?)', $title, $public, $price);
        $event_id = $this->dbh->last_insert_id();
    } catch (\Exception $e) {
        $this->dbh->rollback();
    }

    $event = get_event($this->dbh, $event_id);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->get('/admin/api/events/{id}', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];

    $event = get_event($this->dbh, $event_id);
    if (empty($event)) {
        return res_error($response, 'not_found', 404);
    }

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->post('/admin/api/events/{id}/actions/edit', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $public = $request->getParsedBodyParam('public') ? 1 : 0;
    $closed = $request->getParsedBodyParam('closed') ? 1 : 0;

    if ($closed) {
        $public = 0;
    }

    $event = get_event($this->dbh, $event_id);
    if (empty($event)) {
        return res_error($response, 'not_found', 404);
    }

    if ($event['closed']) {
        return res_error($response, 'cannot_edit_closed_event', 400);
    } elseif ($event['public'] && $closed) {
        return res_error($response, 'cannot_close_public_event', 400);
    }

    try {
        $this->dbh->execute('UPDATE events SET public_fg = ?, closed_fg = ? WHERE id = ?', $public, $closed, $event['id']);
    } catch (\Exception $e) {
        $this->dbh->rollback();
    }

    $event = get_event($this->dbh, $event_id);

    return $response->withJson($event, null, JSON_NUMERIC_CHECK);
})->add($admin_login_required);

$app->get('/admin/api/reports/events/{id}/sales', function (Request $request, Response $response, array $args): Response {
    $event_id = $args['id'];
    $event = get_event($this->dbh, $event_id);

    $reports = [];

    $reservations = $this->dbh->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.event_id = ? ORDER BY reserved_at ASC', $event['id']);
    foreach ($reservations as $reservation) {
        $report = [
            'reservation_id' => $reservation['id'],
            'event_id' => $reservation['event_id'],
            'rank' => $reservation['sheet_rank'],
            'num' => $reservation['sheet_num'],
            'user_id' => $reservation['user_id'],
            'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
            'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
            'price' => $reservation['event_price'] + $reservation['sheet_price'],
        ];

        array_push($reports, $report);
    }

    return render_report_csv($response, $reports);
});

$app->get('/admin/api/reports/sales', function (Request $request, Response $response): Response {
    $reports = [];
    $reservations = $this->dbh->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.id AS event_id, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id ORDER BY reserved_at ASC');
    foreach ($reservations as $reservation) {
        $report = [
            'reservation_id' => $reservation['id'],
            'event_id' => $reservation['event_id'],
            'rank' => $reservation['sheet_rank'],
            'num' => $reservation['sheet_num'],
            'user_id' => $reservation['user_id'],
            'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
            'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
            'price' => $reservation['event_price'] + $reservation['sheet_price'],
        ];

        array_push($reports, $report);
    }

    return render_report_csv($response, $reports);
})->add($admin_login_required);

function render_report_csv(Response $response, array $reports): Response
{
    usort($reports, function ($a, $b) { return $a['sold_at'] > $b['sold_at']; });

    $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
    $body = implode(',', $keys);
    $body .= "\n";
    foreach ($reports as $report) {
        $data = [];
        foreach ($keys as $key) {
            $data[] = $report[$key];
        }
        $body .= implode(',', $data);
        $body .= "\n";
    }

    return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
        ->write($body);
}

function res_error(Response $response, string $error = 'unknown', int $status = 500): Response
{
    return $response->withStatus($status)
        ->withHeader('Content-type', 'application/json')
        ->withJson(['error' => $error]);
}

class PDOWrapper
{
    private $pdo;

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->pdo, $name], $arguments);
    }

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->query('SET SESSION sql_mode="STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"');
    }

    public function select_one(string $query, ...$params)
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $stmt->closeCursor();

        return $row[0];
    }

    public function select_all(string $query, ...$params): array
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function select_row(string $query, ...$params)
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row;
    }

    public function execute($query, ...$params): bool
    {
        $stmt = $this->pdo->prepare($query);

        return $stmt->execute($params);
    }

    public function last_insert_id()
    {
        return $this->pdo->lastInsertId();
    }
}


