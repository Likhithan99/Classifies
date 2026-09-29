<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$database = db();
$databaseError = $database_error ?? '';
$demoMode = $database === null;
$perPage = 20;
$page = (string) ($_GET['page'] ?? 'home');
$action = (string) ($_GET['action'] ?? '');
$categories = $database
    ? $database->query('SELECT id, name, slug, description FROM classified_menus WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll()
    : [];
$areas = $database
    ? $database->query('SELECT id, name FROM areas ORDER BY name, id')->fetchAll()
    : [];
$locations = $database
    ? $database->query('SELECT l.id, l.area_id, l.name AS location_name, a.name AS area_name FROM locations l JOIN areas a ON a.id = l.area_id ORDER BY l.id')->fetchAll()
    : [];
$firstLocationId = $locations ? (int) $locations[0]['id'] : 0;
$requestedLocationId = filter_var($_GET['location_id'] ?? null, FILTER_VALIDATE_INT);
$selectedLocationId = $firstLocationId;
foreach ($locations as $locationOption) {
    if ($requestedLocationId && (int) $locationOption['id'] === $requestedLocationId) {
        $selectedLocationId = $requestedLocationId;
        break;
    }
}
$selectedLocation = null;
foreach ($locations as $locationOption) {
    if ((int) $locationOption['id'] === $selectedLocationId) {
        $selectedLocation = $locationOption;
        break;
    }
}
$user = current_user();

function category_slug(int $id, array $categories): string
{
    foreach ($categories as $category) {
        if ((int) $category['id'] === $id) {
            return (string) $category['slug'];
        }
    }
    return '';
}

function query_listings(?PDO $database, int $offset, int $limit, string $category, string $search, int $locationId): array
{
    if (!$database) {
        return ['items' => [], 'total' => 0];
    }
    {
        $conditions = ['c.location_id = :location_id'];
        $parameters = ['location_id' => $locationId];
        if ($category !== '') {
            $conditions[] = 'm.slug = :slug';
            $parameters['slug'] = $category;
        }
        if ($search !== '') {
            $conditions[] = '(c.title LIKE :search_title OR c.description LIKE :search_description OR l.name LIKE :search_location OR a.name LIKE :search_area)';
            $searchPattern = '%' . $search . '%';
            $parameters['search_title'] = $searchPattern;
            $parameters['search_description'] = $searchPattern;
            $parameters['search_location'] = $searchPattern;
            $parameters['search_area'] = $searchPattern;
        }
        $where = implode(' AND ', $conditions);
        $count = $database->prepare('SELECT COUNT(*) FROM classifieds c JOIN classified_menus m ON m.id = c.menu_id JOIN locations l ON l.id = c.location_id JOIN areas a ON a.id = l.area_id WHERE ' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $statement = $database->prepare(
                'SELECT c.id, c.title, c.description, c.image_url, c.price, l.name AS location, c.is_featured, c.created_at,
                    m.id AS category_id, m.name AS category_name, m.slug AS category_slug
                 FROM classifieds c JOIN classified_menus m ON m.id = c.menu_id
                 JOIN locations l ON l.id = c.location_id JOIN areas a ON a.id = l.area_id WHERE ' . $where .
            ' ORDER BY c.is_featured DESC, c.created_at DESC, c.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'total' => $total];
    }

}

function listing_by_id(?PDO $database, int $id): ?array
{
    if (!$database) {
        return null;
    }
    $statement = $database->prepare(
        'SELECT c.*, m.name AS category_name, m.slug AS category_slug FROM classifieds c
         JOIN classified_menus m ON m.id = c.menu_id WHERE c.id = :id'
    );
    $statement->execute(['id' => $id]);
    $listing = $statement->fetch();
    return $listing ?: null;
}

function require_admin(): void
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        set_flash('The admin area is for administrator accounts.', 'error');
        redirect('/?page=login');
    }
}

function oauth_request(string $url, ?array $post = null, ?string $accessToken = null): array
{
    $headers = ['Accept: application/json'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    $options = ['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true]];
    if ($post !== null) {
        $options['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['http']['content'] = http_build_query($post);
    }
    $result = @file_get_contents($url, false, stream_context_create($options));
    $data = $result === false ? null : json_decode($result, true);
    if (!is_array($data)) {
        throw new RuntimeException('The identity provider could not be reached. Please try again.');
    }
    return $data;
}

if ($action === 'load_listings') {
    header('Content-Type: application/json; charset=utf-8');
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $result = query_listings($database, $offset, $perPage, trim((string) ($_GET['category'] ?? '')), trim((string) ($_GET['q'] ?? '')), $selectedLocationId);
    echo json_encode(['items' => $result['items'], 'total' => $result['total'], 'nextOffset' => $offset + count($result['items'])], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'oauth_start') {
    $providerName = (string) ($_GET['provider'] ?? '');
    $provider = oauth_provider($providerName);
    if (!$provider || $provider['client_id'] === '' || $provider['client_secret'] === '') {
        set_flash('Add this provider\'s client ID and secret to enable social sign-in.', 'error');
        redirect('/?page=login');
    }
    $_SESSION['oauth_state'] = bin2hex(random_bytes(24));
    $_SESSION['oauth_provider'] = $providerName;
    $callback = env_value('OAUTH_REDIRECT_URI', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?action=oauth_callback&provider=' . rawurlencode($providerName));
    $parameters = ['client_id' => $provider['client_id'], 'redirect_uri' => $callback, 'response_type' => 'code', 'scope' => $provider['scope'], 'state' => $_SESSION['oauth_state']];
    redirect($provider['authorize'] . '?' . http_build_query($parameters));
}

if ($action === 'oauth_callback') {
    $providerName = (string) ($_GET['provider'] ?? '');
    $provider = oauth_provider($providerName);
    $state = (string) ($_GET['state'] ?? '');
    $savedState = (string) ($_SESSION['oauth_state'] ?? '');
    unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);
    try {
        if (!$provider || $savedState === '' || !hash_equals($savedState, $state) || isset($_GET['error'])) {
            throw new RuntimeException('Social sign-in was cancelled or could not be verified.');
        }
        if (!$database) {
            throw new RuntimeException('Connect the site to MySQL before using social sign-in.');
        }
        $callback = env_value('OAUTH_REDIRECT_URI', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?action=oauth_callback&provider=' . rawurlencode($providerName));
        $token = oauth_request($provider['token'], [
            'client_id' => $provider['client_id'], 'client_secret' => $provider['client_secret'], 'code' => (string) ($_GET['code'] ?? ''),
            'redirect_uri' => $callback, 'grant_type' => 'authorization_code',
        ]);
        if (empty($token['access_token'])) {
            throw new RuntimeException('The identity provider did not return an access token.');
        }
        $profile = oauth_request($provider['userinfo'], null, (string) $token['access_token']);
        $email = filter_var((string) ($profile['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $verified = $providerName === 'facebook' || ($profile['email_verified'] ?? false) === true || ($profile['email_verified'] ?? '') === 'true';
        if (!$email || !$verified || empty($profile['sub']) && empty($profile['id'])) {
            throw new RuntimeException('A verified email address is required to sign in.');
        }
        $statement = $database->prepare('SELECT id, email, name, role FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        $account = $statement->fetch();
        if (!$account) {
            $name = trim((string) ($profile['name'] ?? '')) ?: strstr($email, '@', true);
            $insert = $database->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, NULL, \'member\')');
            $insert->execute(['name' => $name, 'email' => $email]);
            $account = ['id' => (int) $database->lastInsertId(), 'email' => $email, 'name' => $name, 'role' => 'member'];
        }
        session_regenerate_id(true);
        $_SESSION['user'] = $account;
        redirect($account['role'] === 'admin' ? '/?page=admin' : '/?page=dashboard');
    } catch (Throwable $exception) {
        set_flash($exception->getMessage(), 'error');
        redirect('/?page=login');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');
    if ($formAction === 'login') {
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $statement = $database && $email ? $database->prepare('SELECT id, email, name, role, password_hash FROM users WHERE email = :email') : null;
        if ($statement) {
            $statement->execute(['email' => $email]);
        }
        $account = $statement ? $statement->fetch() : false;
        if ($account && $account['password_hash'] && password_verify($password, $account['password_hash'])) {
            session_regenerate_id(true);
            unset($account['password_hash']);
            $_SESSION['user'] = $account;
            redirect($account['role'] === 'admin' ? '/?page=admin' : '/?page=dashboard');
        }
        set_flash($database ? 'That email and password combination was not recognized.' : 'Connect MySQL to sign in. Demo browsing is ready without an account.', 'error');
        redirect('/?page=login');
    }
    if ($formAction === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        if (!$database) {
            set_flash('Connect MySQL before registering a new account.', 'error');
        } elseif ($name === '' || !$email || strlen($password) < 8) {
            set_flash('Enter your name, a valid email, and a password of at least 8 characters.', 'error');
        } else {
            try {
                $statement = $database->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, \'member\')');
                $statement->execute(['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int) $database->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'member'];
                redirect('/?page=dashboard');
            } catch (PDOException $exception) {
                set_flash('That email address is already registered.', 'error');
            }
        }
        redirect('/?page=register');
    }
    if ($formAction === 'logout') {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        set_flash('You are signed out. See you around.', 'success');
        redirect('/');
    }
    if ($formAction === 'create_category') {
        require_admin();
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        if (!$database) {
            set_flash('Connect MySQL to save a menu category.', 'error');
        } elseif ($name === '' || strlen($name) > 80 || $slug === '') {
            set_flash('Enter a category name (up to 80 characters).', 'error');
        } else {
            try {
                $statement = $database->prepare('INSERT INTO classified_menus (name, slug, description) VALUES (:name, :slug, :description)');
                $statement->execute(['name' => $name, 'slug' => $slug, 'description' => $description]);
                set_flash('The new menu category is live.', 'success');
            } catch (PDOException $exception) {
                set_flash('That category name or URL is already in use.', 'error');
            }
        }
        redirect('/?page=admin');
    }
    if ($formAction === 'create_area') {
        require_admin();
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!$database) {
            set_flash('Connect MySQL to save an area.', 'error');
        } elseif ($name === '' || strlen($name) > 100) {
            set_flash('Enter an area name up to 100 characters.', 'error');
        } else {
            try {
                $statement = $database->prepare('INSERT INTO areas (name) VALUES (:name)');
                $statement->execute(['name' => $name]);
                set_flash('The area has been created. Add locations to it next.', 'success');
            } catch (PDOException $exception) {
                set_flash('That area name is already in use.', 'error');
            }
        }
        redirect('/?page=admin');
    }
    if ($formAction === 'create_location') {
        require_admin();
        $areaId = filter_var($_POST['area_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $areaStatement = $database && $areaId
            ? $database->prepare('SELECT id FROM areas WHERE id = :id')
            : null;
        if ($areaStatement) {
            $areaStatement->execute(['id' => $areaId]);
        }
        if (!$database) {
            set_flash('Connect MySQL to save a location.', 'error');
        } elseif (!$areaId || !$areaStatement->fetchColumn() || $name === '' || strlen($name) > 120) {
            set_flash('Choose an area and enter a location name up to 120 characters.', 'error');
        } else {
            try {
                $statement = $database->prepare('INSERT INTO locations (area_id, name) VALUES (:area_id, :name)');
                $statement->execute(['area_id' => $areaId, 'name' => $name]);
                set_flash('The location has been added to its area.', 'success');
            } catch (PDOException $exception) {
                set_flash('That location already exists in this area.', 'error');
            }
        }
        redirect('/?page=admin');
    }
    if ($formAction === 'create_classified') {
        $user = current_user();
        if (!$user) {
            set_flash('Log in before creating a classified.', 'error');
            redirect('/?page=login');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $menuId = filter_var($_POST['menu_id'] ?? null, FILTER_VALIDATE_INT);
        $locationId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
        $menuStatement = $database ? $database->prepare('SELECT id FROM classified_menus WHERE id = :id AND is_active = 1') : null;
        if ($menuStatement) {
            $menuStatement->execute(['id' => $menuId]);
        }
        $locationStatement = $database && $locationId ? $database->prepare('SELECT name FROM locations WHERE id = :id') : null;
        if ($locationStatement) {
            $locationStatement->execute(['id' => $locationId]);
        }
        $locationName = $locationStatement ? $locationStatement->fetchColumn() : false;
        if (!$database) {
            set_flash('Connect MySQL to publish a classified.', 'error');
        } elseif ($title === '' || $description === '' || $price === false || $price < 0 || !$menuId || !$menuStatement->fetchColumn() || !$locationId || !$locationName || !filter_var($imageUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($imageUrl, PHP_URL_SCHEME), ['https'], true)) {
            set_flash('Choose an available menu and location, fill in all fields, and use a valid HTTPS image URL.', 'error');
        } else {
            $isAdmin = $user['role'] === 'admin';
            $statement = $database->prepare('INSERT INTO classifieds (menu_id, user_id, location_id, title, description, image_url, price, location, is_featured, is_premium) VALUES (:menu_id, :user_id, :location_id, :title, :description, :image_url, :price, :location, :is_featured, :is_premium)');
            $statement->execute(['menu_id' => $menuId, 'user_id' => $user['id'], 'location_id' => $locationId, 'title' => $title, 'description' => $description, 'image_url' => $imageUrl, 'price' => $price, 'location' => $locationName, 'is_featured' => $isAdmin && isset($_POST['is_featured']) ? 1 : 0, 'is_premium' => $isAdmin && isset($_POST['is_premium']) ? 1 : 0]);
            set_flash('Your classified is published.', 'success');
        }
        redirect($user['role'] === 'admin' ? '/?page=admin' : '/?page=dashboard');
    }
    if ($formAction === 'update_classified') {
        $user = current_user();
        if (!$user) {
            set_flash('Log in before editing a classified.', 'error');
            redirect('/?page=login');
        }
        $classifiedId = filter_var($_POST['classified_id'] ?? null, FILTER_VALIDATE_INT);
        $ownerStatement = $database && $classifiedId
            ? $database->prepare('SELECT user_id, is_featured, is_premium FROM classifieds WHERE id = :id')
            : null;
        if ($ownerStatement) {
            $ownerStatement->execute(['id' => $classifiedId]);
        }
        $existing = $ownerStatement ? $ownerStatement->fetch() : false;
        $isAdmin = $user['role'] === 'admin';
        if (!$existing || (!$isAdmin && (int) $existing['user_id'] !== (int) $user['id'])) {
            set_flash('You do not have permission to edit that classified.', 'error');
            redirect($isAdmin ? '/?page=admin' : '/?page=dashboard');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $menuId = filter_var($_POST['menu_id'] ?? null, FILTER_VALIDATE_INT);
        $locationId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
        $menuStatement = $database->prepare('SELECT id FROM classified_menus WHERE id = :id AND is_active = 1');
        $menuStatement->execute(['id' => $menuId]);
        $locationStatement = $locationId ? $database->prepare('SELECT name FROM locations WHERE id = :id') : null;
        if ($locationStatement) {
            $locationStatement->execute(['id' => $locationId]);
        }
        $locationName = $locationStatement ? $locationStatement->fetchColumn() : false;
        if ($title === '' || strlen($title) > 160 || $description === '' || $price === false || $price < 0 || !$menuId || !$menuStatement->fetchColumn() || !$locationId || !$locationName || !filter_var($imageUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($imageUrl, PHP_URL_SCHEME), ['https'], true)) {
            set_flash('Choose an available menu and location, fill in all fields, and use a valid HTTPS image URL.', 'error');
            redirect('/?page=edit_classified&id=' . (int) $classifiedId);
        }
        $statement = $database->prepare(
            'UPDATE classifieds SET menu_id = :menu_id, location_id = :location_id, title = :title, description = :description,
             image_url = :image_url, price = :price, location = :location,
             is_featured = :is_featured, is_premium = :is_premium WHERE id = :id'
        );
        $statement->execute([
            'menu_id' => $menuId,
            'location_id' => $locationId,
            'title' => $title,
            'description' => $description,
            'image_url' => $imageUrl,
            'price' => $price,
            'location' => $locationName,
            'is_featured' => $isAdmin ? (isset($_POST['is_featured']) ? 1 : 0) : (int) $existing['is_featured'],
            'is_premium' => $isAdmin ? (isset($_POST['is_premium']) ? 1 : 0) : (int) $existing['is_premium'],
            'id' => $classifiedId,
        ]);
        set_flash('The classified has been updated.', 'success');
        redirect($isAdmin ? '/?page=admin' : '/?page=dashboard');
    }
    if ($formAction === 'delete_classified') {
        $user = current_user();
        if (!$user) {
            set_flash('Log in before deleting a classified.', 'error');
            redirect('/?page=login');
        }
        $classifiedId = filter_var($_POST['classified_id'] ?? null, FILTER_VALIDATE_INT);
        $ownerStatement = $database && $classifiedId
            ? $database->prepare('SELECT user_id FROM classifieds WHERE id = :id')
            : null;
        if ($ownerStatement) {
            $ownerStatement->execute(['id' => $classifiedId]);
        }
        $existing = $ownerStatement ? $ownerStatement->fetch() : false;
        $isAdmin = $user['role'] === 'admin';
        if (!$existing || (!$isAdmin && (int) $existing['user_id'] !== (int) $user['id'])) {
            set_flash('You do not have permission to delete that classified.', 'error');
        } else {
            $delete = $database->prepare('DELETE FROM classifieds WHERE id = :id');
            $delete->execute(['id' => $classifiedId]);
            set_flash('The classified has been deleted.', 'success');
        }
        redirect($isAdmin ? '/?page=admin' : '/?page=dashboard');
    }
}

if ($page === 'dashboard') {
    if (!$user) {
        set_flash('Log in to open your dashboard.', 'error');
        redirect('/?page=login');
    }
    if ($user['role'] === 'admin') {
        redirect('/?page=admin');
    }
}
if ($page === 'admin') {
    require_admin();
}
if ($page === 'edit_classified') {
    if (!$user) {
        set_flash('Log in to edit a classified.', 'error');
        redirect('/?page=login');
    }
    $classifiedId = max(0, (int) ($_GET['id'] ?? 0));
    $editStatement = $database ? $database->prepare(
        'SELECT c.* FROM classifieds c WHERE c.id = :id AND (:is_admin = 1 OR c.user_id = :user_id)'
    ) : null;
    if ($editStatement) {
        $editStatement->execute(['id' => $classifiedId, 'is_admin' => $user['role'] === 'admin' ? 1 : 0, 'user_id' => $user['id']]);
    }
    $editListing = $editStatement ? $editStatement->fetch() : false;
    if (!$editListing) {
        http_response_code(404);
        set_flash('That classified was not found or you do not have permission to edit it.', 'error');
        redirect($user['role'] === 'admin' ? '/?page=admin' : '/?page=dashboard');
    }
}

$categorySlug = trim((string) ($_GET['category'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$selectedCategory = null;
foreach ($categories as $category) {
    if ($category['slug'] === $categorySlug) {
        $selectedCategory = $category;
        break;
    }
}
$catalog = query_listings($database, 0, $perPage, $categorySlug, $searchQuery, $selectedLocationId);
$premiumListings = [];
if ($database) {
    $premiumQuery = 'SELECT c.id, c.title, c.description, c.image_url, c.price, l.name AS location, m.slug AS category_slug
                     FROM classifieds c JOIN classified_menus m ON m.id = c.menu_id
                     JOIN locations l ON l.id = c.location_id
                     WHERE c.is_premium = 1 AND c.location_id = :location_id';
    $premiumParameters = ['location_id' => $selectedLocationId];
    if ($selectedCategory) {
        $premiumQuery .= ' AND c.menu_id = :menu_id';
        $premiumParameters['menu_id'] = $selectedCategory['id'];
    }
    if ($selectedCategory || $categorySlug === '') {
        $premiumStatement = $database->prepare($premiumQuery . ' ORDER BY c.created_at DESC LIMIT 4');
        $premiumStatement->execute($premiumParameters);
        $premiumListings = $premiumStatement->fetchAll();
    }
} else {
    $premiumListings = [];
}
$flash = take_flash();
$listing = $page === 'listing' ? listing_by_id($database, max(0, (int) ($_GET['id'] ?? 0))) : null;
if ($page === 'listing' && !$listing) {
    http_response_code(404);
}
$visibleCount = count($catalog['items']);
if ($page === 'dashboard' || $page === 'admin' || $page === 'edit_classified') {
    $dashboardCategories = $database
        ? $database->query('SELECT id, name FROM classified_menus WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll()
        : [];
    if ($database && $page === 'admin') {
        $dashboardListings = $database->query(
            'SELECT c.id, c.title, c.price, c.created_at, m.name AS category_name, u.name AS owner_name
             FROM classifieds c JOIN classified_menus m ON m.id = c.menu_id
             LEFT JOIN users u ON u.id = c.user_id ORDER BY c.created_at DESC LIMIT 100'
        )->fetchAll();
        $dashboardListingCount = (int) $database->query('SELECT COUNT(*) FROM classifieds')->fetchColumn();
    } elseif ($database) {
        $statement = $database->prepare(
            'SELECT c.id, c.title, c.price, c.created_at, m.name AS category_name
             FROM classifieds c JOIN classified_menus m ON m.id = c.menu_id
             WHERE c.user_id = :user_id ORDER BY c.created_at DESC'
        );
        $statement->execute(['user_id' => $user['id']]);
        $dashboardListings = $statement->fetchAll();
        $dashboardListingCount = count($dashboardListings);
    } else {
        $dashboardListings = [];
        $dashboardListingCount = 0;
    }
}
if ($page === 'dashboard' || $page === 'admin' || $page === 'edit_classified'):
    $isEditingClassified = $page === 'edit_classified';
    $isAdminDashboard = $user['role'] === 'admin';
    $dashboardTitle = $isEditingClassified ? 'Edit classified' : ($isAdminDashboard ? 'Market desk' : 'Your dashboard');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f3f5f1">
    <title><?= h($dashboardTitle) ?> | Common Ground</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/site.css">
</head>
<body class="workspace-body">
<header class="workspace-header">
    <a class="brand" href="/" aria-label="Common Ground home"><span class="brand-mark">cg</span><span class="brand-copy"><strong>common ground</strong><small>the neighborhood marketplace</small></span></a>
    <nav class="workspace-nav" aria-label="Dashboard navigation"><a href="/">View marketplace</a><a href="/?page=<?= $isAdminDashboard ? 'admin' : 'dashboard' ?>"><?= $isEditingClassified ? 'Back to dashboard' : ($isAdminDashboard ? 'Admin dashboard' : 'My dashboard') ?></a><span class="workspace-user"><?= h($user['name']) ?></span><form action="/" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="logout"><button class="text-button" type="submit">Sign out</button></form></nav>
</header>
<main class="workspace-main">
    <?php if ($flash): ?><div class="notice notice-<?= h($flash['kind']) ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php if ($demoMode): ?><div class="demo-banner"><span class="demo-pulse"></span><span><strong>Database unavailable</strong> <?= h($databaseError !== '' ? $databaseError : 'Connect MySQL to manage classifieds.') ?></span></div><?php endif; ?>
    <section class="page-heading workspace-heading"><span class="eyebrow"><?= $isAdminDashboard ? 'ADMINISTRATOR WORKSPACE' : 'MEMBER WORKSPACE' ?></span><h1><?= h($dashboardTitle) ?><span class="heading-period">.</span></h1><p><?= $isEditingClassified ? 'Update the details and category for this classified.' : ($isAdminDashboard ? 'Manage categories and every classified in the marketplace.' : 'Create a classified and keep track of your posts.') ?></p></section>
    <?php if (!$isEditingClassified): ?><div class="admin-stats"><div><span><?= $isAdminDashboard ? 'ACTIVE CATEGORIES' : 'YOUR CLASSIFIEDS' ?></span><strong><?= $isAdminDashboard ? count($dashboardCategories) : $dashboardListingCount ?></strong></div><div><span><?= $isAdminDashboard ? 'ALL CLASSIFIEDS' : 'AVAILABLE CATEGORIES' ?></span><strong><?= $isAdminDashboard ? $dashboardListingCount : count($dashboardCategories) ?></strong></div><div><span>ACCOUNT</span><strong><?= $isAdminDashboard ? 'Admin' : 'Member' ?></strong></div></div><?php endif; ?>
    <div class="workspace-grid <?= $isAdminDashboard ? 'workspace-admin-grid' : '' ?>">
        <?php if ($isEditingClassified): ?>
            <section class="admin-form-panel"><div class="panel-title"><span class="panel-index panel-index-orange">↗</span><div><h2>Classified details</h2><p>Changes appear in the marketplace after saving.</p></div></div><form class="stack-form" action="/" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="update_classified"><input type="hidden" name="classified_id" value="<?= (int) $editListing['id'] ?>"><label>Category<select name="menu_id" required><?php foreach ($dashboardCategories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $editListing['menu_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= h($category['name']) ?></option><?php endforeach; ?></select></label><label>Title<input name="title" maxlength="160" value="<?= h($editListing['title']) ?>" required></label><label>Description<textarea name="description" rows="4" required><?= h($editListing['description']) ?></textarea></label><div class="form-row"><label>Price<input type="number" name="price" min="0" step="0.01" value="<?= h($editListing['price']) ?>" required></label><label>Location<select name="location_id" required><?php foreach ($locations as $locationOption): ?><option value="<?= (int) $locationOption['id'] ?>" <?= (int) ($editListing['location_id'] ?? $selectedLocationId) === (int) $locationOption['id'] ? 'selected' : '' ?>><?= h($locationOption['area_name'] . ' — ' . $locationOption['location_name']) ?></option><?php endforeach; ?></select></label></div><label>Image URL<input type="url" name="image_url" value="<?= h($editListing['image_url']) ?>" required></label><?php if ($isAdminDashboard): ?><label class="check-row"><input type="checkbox" name="is_featured" value="1" <?= (int) $editListing['is_featured'] === 1 ? 'checked' : '' ?>> Mark as a neighbor pick</label><label class="check-row"><input type="checkbox" name="is_premium" value="1" <?= (int) $editListing['is_premium'] === 1 ? 'checked' : '' ?>> Include in premium picks</label><?php endif; ?><div class="form-actions"><button class="primary-button" type="submit">Save changes <span>↗</span></button><a class="secondary-button" href="/?page=<?= $isAdminDashboard ? 'admin' : 'dashboard' ?>">Cancel</a></div></form></section>
        <?php else: ?>
        <?php if ($isAdminDashboard): ?>
            <section class="admin-form-panel"><div class="panel-title"><span class="panel-index">01</span><div><h2>Create a category</h2><p>Categories are managed by administrators.</p></div></div><form class="stack-form" action="/?page=admin" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="create_category"><label>Category name<input name="name" maxlength="80" placeholder="e.g. Music & instruments" required></label><label>Description<input name="description" maxlength="255" placeholder="A short description"></label><button class="primary-button" type="submit">Add category <span>↗</span></button></form><div class="workspace-category-list"><h3>Active categories</h3><?php if ($dashboardCategories): ?><ul><?php foreach ($dashboardCategories as $category): ?><li><?= h($category['name']) ?></li><?php endforeach; ?></ul><?php else: ?><p>No categories have been created yet.</p><?php endif; ?></div></section>
        <?php endif; ?>
        <section class="admin-form-panel"><div class="panel-title"><span class="panel-index panel-index-orange"><?= $isAdminDashboard ? '02' : '+' ?></span><div><h2>Create a classified</h2><p>Choose an administrator-created category and location.</p></div></div><form class="stack-form" action="/?page=<?= $isAdminDashboard ? 'admin' : 'dashboard' ?>" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="create_classified"><label>Category<select name="menu_id" required <?= $dashboardCategories ? '' : 'disabled' ?>><option value="">Select a category</option><?php foreach ($dashboardCategories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= h($category['name']) ?></option><?php endforeach; ?></select></label><?php if (!$dashboardCategories): ?><p class="form-hint">An administrator must create a category before classifieds can be posted.</p><?php endif; ?><label>Title<input name="title" maxlength="160" placeholder="What are you sharing?" required></label><label>Description<textarea name="description" rows="4" placeholder="Share the details that matter." required></textarea></label><div class="form-row"><label>Price<input type="number" name="price" min="0" step="0.01" placeholder="0.00" required></label><label>Location<select name="location_id" required <?= $locations ? '' : 'disabled' ?>><option value="">Select an area and location</option><?php foreach ($locations as $locationOption): ?><option value="<?= (int) $locationOption['id'] ?>" <?= (int) $locationOption['id'] === $selectedLocationId ? 'selected' : '' ?>><?= h($locationOption['area_name'] . ' — ' . $locationOption['location_name']) ?></option><?php endforeach; ?></select></label></div><?php if (!$locations): ?><p class="form-hint">An administrator must create an area and at least one location before classifieds can be posted.</p><?php endif; ?><label>Image URL<input type="url" name="image_url" placeholder="https://..." required></label><?php if ($isAdminDashboard): ?><label class="check-row"><input type="checkbox" name="is_featured" value="1"> Mark as a neighbor pick</label><label class="check-row"><input type="checkbox" name="is_premium" value="1"> Include in premium picks</label><?php endif; ?><button class="primary-button" type="submit" <?= $dashboardCategories && $locations ? '' : 'disabled' ?>>Publish classified <span>↗</span></button></form></section>
        <?php endif; ?>
    </div>
    <?php if ($isAdminDashboard && !$isEditingClassified): ?>
    <section class="location-admin-panel">
        <div class="panel-title"><span class="panel-index">03</span><div><h2>Manage areas and locations</h2><p>Locations are grouped under the areas you create.</p></div></div>
        <div class="location-admin-forms">
            <form class="stack-form" action="/?page=admin" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="create_area"><label>New area<input name="name" maxlength="100" placeholder="e.g. Northside" required></label><button class="secondary-button" type="submit">Add area</button></form>
            <form class="stack-form" action="/?page=admin" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="create_location"><label>Area<select name="area_id" required <?= $areas ? '' : 'disabled' ?>><option value="">Select an area</option><?php foreach ($areas as $area): ?><option value="<?= (int) $area['id'] ?>"><?= h($area['name']) ?></option><?php endforeach; ?></select></label><label>New location<input name="name" maxlength="120" placeholder="e.g. Riverside" required></label><button class="secondary-button" type="submit" <?= $areas ? '' : 'disabled' ?>>Add location</button></form>
        </div>
        <div class="area-location-list">
            <?php if ($areas): foreach ($areas as $area): ?><section><h3><?= h($area['name']) ?></h3><ul><?php $areaHasLocations = false; foreach ($locations as $locationOption): if ((int) $locationOption['area_id'] === (int) $area['id']): $areaHasLocations = true; ?><li><?= h($locationOption['location_name']) ?></li><?php endif; endforeach; ?><?php if (!$areaHasLocations): ?><li class="location-empty">No locations yet</li><?php endif; ?></ul></section><?php endforeach; else: ?><p class="form-hint">Create an area before adding its locations.</p><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php if (!$isEditingClassified): ?>
    <?php if ($isAdminDashboard): ?><section class="workspace-listings"><div class="section-head"><h2>All classifieds</h2><span>Latest first</span></div><?php else: ?><section class="workspace-listings"><div class="section-head"><h2>Your classifieds</h2><span><?= $dashboardListingCount ?> published</span></div><?php endif; ?>
        <?php if ($dashboardListings): ?><div class="workspace-table-wrap"><table class="workspace-table"><thead><tr><th>Classified</th><th>Category</th><?php if ($isAdminDashboard): ?><th>Posted by</th><?php endif; ?><th>Price</th><th>Posted</th><th>Actions</th></tr></thead><tbody><?php foreach ($dashboardListings as $dashboardListing): ?><tr><td><a href="/?page=listing&amp;id=<?= (int) $dashboardListing['id'] ?>"><?= h($dashboardListing['title']) ?></a></td><td><?= h($dashboardListing['category_name']) ?></td><?php if ($isAdminDashboard): ?><td><?= h($dashboardListing['owner_name'] ?? 'Admin') ?></td><?php endif; ?><td>$<?= number_format((float) $dashboardListing['price'], 2) ?></td><td><?= h(date('M j, Y', strtotime((string) $dashboardListing['created_at']))) ?></td><td><div class="row-actions"><a class="secondary-button" href="/?page=edit_classified&amp;id=<?= (int) $dashboardListing['id'] ?>">Edit</a><form action="/" method="post" onsubmit="return confirm('Delete this classified? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="delete_classified"><input type="hidden" name="classified_id" value="<?= (int) $dashboardListing['id'] ?>"><button class="danger-button" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php if ($isAdminDashboard && $dashboardListingCount > count($dashboardListings)): ?><p class="form-hint">Showing the latest 100 of <?= $dashboardListingCount ?> classifieds.</p><?php endif; ?><?php else: ?><div class="workspace-empty"><h2><?= $isAdminDashboard ? 'No classifieds yet.' : 'You have not posted a classified yet.' ?></h2><p><?= $isAdminDashboard ? 'Published classifieds will appear here.' : 'Your published classifieds will appear here.' ?></p></div><?php endif; ?>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
<?php return; endif; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f3f5f1">
    <meta name="description" content="Good finds, good neighbors. Discover a more local kind of classifieds.">
    <title><?= h($page === 'login' ? 'Welcome back' : ($page === 'register' ? 'Join Common Ground' : ($page === 'admin' ? 'Market desk' : 'Common Ground | Local finds, good people'))) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/site.css">
    <script src="/assets/site.js" defer></script>
</head>
<body<?= ($page === 'login' || $page === 'register') ? ' class="auth-only"' : '' ?>>
<?php if ($page === 'login' || $page === 'register'): ?>
<header class="site-header auth-header">
    <a class="brand" href="/" aria-label="Common Ground home"><span class="brand-mark">cg</span><span class="brand-copy"><strong>common ground</strong><small>the neighborhood marketplace</small></span></a>
    <a class="back-link auth-back-link" href="/">← Back to the market</a>
</header>
<main class="auth-only-content">
    <?php if ($flash): ?><div class="notice notice-<?= h($flash['kind']) ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php if ($demoMode): ?><div class="demo-banner"><span class="demo-pulse"></span><span><strong>Database unavailable</strong> <?= h($databaseError !== '' ? $databaseError : 'Connect MySQL to load marketplace data.') ?></span></div><?php endif; ?>
    <?php if ($page === 'login'): ?>
        <section class="auth-panel">
            <div class="auth-intro"><span class="eyebrow">YOUR PEOPLE ARE HERE</span><h1>Good to see<br>you again.</h1><p>Pick up right where your neighborhood left off.</p></div>
            <div class="auth-form-wrap"><h2>Log in</h2><form class="stack-form" method="post" action="/?page=login"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="login"><label>Email address<input type="email" name="email" autocomplete="email" placeholder="you@example.com" required></label><label>Password<input type="password" name="password" autocomplete="current-password" placeholder="Your password" required></label><button class="primary-button" type="submit">Welcome back <span>↗</span></button></form><div class="auth-divider"><span>or continue with</span></div><div class="social-buttons"><a href="/?action=oauth_start&amp;provider=google" class="social-button"><span class="google-mark">G</span>Google</a><a href="/?action=oauth_start&amp;provider=facebook" class="social-button"><span class="facebook-mark">f</span>Facebook</a><a href="/?action=oauth_start&amp;provider=linkedin" class="social-button"><span class="linkedin-mark">in</span>LinkedIn</a></div><p class="auth-switch">New to the neighborhood? <a href="/?page=register">Create an account <span>→</span></a></p></div>
        </section>
    <?php else: ?>
        <section class="auth-panel register-panel">
            <div class="auth-intro"><span class="eyebrow">A LITTLE MORE LOCAL</span><h1>Find your<br>kind of people.</h1><p>Join the good stuff happening right around you.</p></div>
            <div class="auth-form-wrap"><h2>Make yourself at home</h2><form class="stack-form" method="post" action="/?page=register"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="register"><label>Your name<input type="text" name="name" autocomplete="name" placeholder="How should we call you?" required maxlength="120"></label><label>Email address<input type="email" name="email" autocomplete="email" placeholder="you@example.com" required></label><label>Choose a password<input type="password" name="password" autocomplete="new-password" placeholder="At least 8 characters" required minlength="8"></label><button class="primary-button" type="submit">Create my account <span>↗</span></button></form><p class="auth-switch">Already part of the neighborhood? <a href="/?page=login">Log in <span>→</span></a></p></div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
<?php return; endif; ?>
<header class="site-header">
    <a class="brand" href="/" aria-label="Common Ground home"><span class="brand-mark">cg</span><span class="brand-copy"><strong>common ground</strong><small>the neighborhood marketplace</small></span></a>
    <form class="header-search" action="/" method="get" role="search">
        <input type="hidden" name="page" value="home">
        <input type="hidden" name="location_id" value="<?= $selectedLocationId ?>">
        <label class="search-field"><span class="sr-only">Search classifieds</span><span class="search-glyph" aria-hidden="true">⌕</span><input name="q" value="<?= h($searchQuery) ?>" placeholder="Find something good..." autocomplete="off"></label>
        <label class="filter-field"><span class="sr-only">Filter by category</span><select name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= h($category['slug']) ?>" <?= $categorySlug === $category['slug'] ? 'selected' : '' ?>><?= h($category['name']) ?></option><?php endforeach; ?></select></label>
        <div class="location-picker" data-location-picker>
            <button class="location-picker-trigger" type="button" aria-expanded="false" aria-haspopup="true"><span aria-hidden="true">⌖</span><span data-selected-location><?= $selectedLocation ? h($selectedLocation['location_name']) : 'Choose location' ?></span><span aria-hidden="true">⌄</span></button>
            <div class="location-picker-menu" data-location-menu hidden>
                <label class="location-search"><span class="sr-only">Search areas and locations</span><input type="search" placeholder="Search areas or locations" autocomplete="off" data-location-search></label>
                <div class="location-menu-columns"><div class="location-area-list" role="tablist" aria-label="Areas">
                    <?php foreach ($areas as $area): ?><button type="button" class="location-area-option <?= $selectedLocation && (int) $selectedLocation['area_id'] === (int) $area['id'] ? 'is-active' : '' ?>" data-area-choice data-area-id="<?= (int) $area['id'] ?>" role="tab" aria-selected="<?= $selectedLocation && (int) $selectedLocation['area_id'] === (int) $area['id'] ? 'true' : 'false' ?>"><?= h($area['name']) ?><span aria-hidden="true">›</span></button><?php endforeach; ?>
                    <?php if (!$areas): ?><p class="location-empty">No areas available yet.</p><?php endif; ?>
                </div><div class="location-choice-list" data-location-choices role="tabpanel">
                    <?php foreach ($areas as $area): ?><div class="location-group <?= $selectedLocation && (int) $selectedLocation['area_id'] === (int) $area['id'] ? 'is-active' : '' ?>" data-area-group="<?= (int) $area['id'] ?>" data-area-name="<?= h($area['name']) ?>"><?php foreach ($locations as $locationOption): if ((int) $locationOption['area_id'] === (int) $area['id']): ?><button type="button" class="location-choice" data-location-choice data-location-id="<?= (int) $locationOption['id'] ?>" data-location-name="<?= h($locationOption['location_name']) ?>" data-area-name="<?= h($area['name']) ?>"><?= h($locationOption['location_name']) ?><?php if ((int) $locationOption['id'] === $selectedLocationId): ?><span aria-hidden="true">✓</span><?php endif; ?></button><?php endif; endforeach; ?></div><?php endforeach; ?>
                </div></div>
            </div>
        </div>
        <button class="search-submit" type="submit" aria-label="Search classifieds">Search</button>
    </form>
    <?php if ($user): ?><div class="account-menu"><span class="avatar" aria-hidden="true"><?= h(strtoupper(substr((string) $user['name'], 0, 1))) ?></span><span class="account-name"><?= h($user['name']) ?></span><a class="account-link" href="/?page=<?= $user['role'] === 'admin' ? 'admin' : 'dashboard' ?>"><?= $user['role'] === 'admin' ? 'Admin desk' : 'Dashboard' ?></a><form action="/" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="logout"><button class="text-button" type="submit">Sign out</button></form></div><?php else: ?><a class="login-button" href="/?page=login">Log in <span aria-hidden="true">↗</span></a><?php endif; ?>
</header>
<div class="site-frame">
    <aside class="left-rail" aria-label="Classified categories">
        <div class="rail-heading"><span>Browse</span><span class="rail-count">0<?= count($categories) ?></span></div>
        <nav class="category-nav">
            <a class="category-link <?= $categorySlug === '' ? 'is-active' : '' ?>" href="/?location_id=<?= $selectedLocationId ?>" aria-current="<?= $categorySlug === '' ? 'page' : 'false' ?>"><span class="category-dot dot-all">✳</span><span>Everything</span></a>
            <?php foreach ($categories as $category): ?><a class="category-link <?= $categorySlug === $category['slug'] ? 'is-active' : '' ?>" href="/?category=<?= rawurlencode((string) $category['slug']) ?>&amp;location_id=<?= $selectedLocationId ?>" aria-current="<?= $categorySlug === $category['slug'] ? 'page' : 'false' ?>"><span class="category-dot dot-<?= (int) $category['id'] ?>"><?= h(strtoupper(substr((string) $category['name'], 0, 1))) ?></span><span><?= h($category['name']) ?></span></a><?php endforeach; ?>
        </nav>
        <a class="sell-note" href="/?page=<?= $user ? ($user['role'] === 'admin' ? 'admin' : 'dashboard') : 'login' ?>"><span class="sell-note-icon">+</span><span><strong>Have something to share?</strong><small>Post a listing for your neighbors.</small></span><span class="sell-arrow">↗</span></a>
        <?php if ($user && $user['role'] === 'admin'): ?><a class="admin-nav-link" href="/?page=admin">Admin market desk <span>↗</span></a><?php endif; ?>
        <div class="local-note"><span class="local-spark">✳</span><p>Good finds travel.<br><strong>Good neighbors stay.</strong></p></div>
    </aside>
    <main class="main-content">
        <?php if ($flash): ?><div class="notice notice-<?= h($flash['kind']) ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php if ($demoMode): ?><div class="demo-banner"><span class="demo-pulse"></span><span><strong>Preview mode</strong> <?= h($databaseError !== '' ? $databaseError : 'Connect MySQL to enable accounts, publishing, and saved listings.') ?></span></div><?php endif; ?>

        <?php if ($page === 'admin'): ?>
            <?php
            $adminCategories = $database ? $database->query('SELECT id, name FROM classified_menus WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll() : [];
            $adminCounts = ['categories' => count($adminCategories), 'listings' => $database ? (int) $database->query('SELECT COUNT(*) FROM classifieds')->fetchColumn() : 0];
            ?>
            <section class="page-heading admin-heading"><span class="eyebrow">ADMINISTRATOR WORKSPACE</span><h1>The market desk<span class="heading-period">.</span></h1><p>Shape what your neighborhood can find today.</p></section>
            <div class="admin-stats"><div><span>MENU CATEGORIES</span><strong><?= $adminCounts['categories'] ?></strong></div><div><span>CLASSIFIEDS</span><strong><?= $adminCounts['listings'] ?></strong></div><div><span>YOUR ACCESS</span><strong>Admin</strong></div></div>
            <div class="admin-forms"><section class="admin-form-panel"><div class="panel-title"><span class="panel-index">01</span><div><h2>Create a menu item</h2><p>Give neighbors a new way to browse.</p></div></div><form class="stack-form" action="/?page=admin" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="create_category"><label>Menu name<input name="name" maxlength="80" placeholder="e.g. Music & instruments" required></label><label>Short description<input name="description" maxlength="255" placeholder="A few words about this corner"></label><button class="primary-button" type="submit">Add menu item <span>↗</span></button></form></section>
            <section class="admin-form-panel"><div class="panel-title"><span class="panel-index panel-index-orange">02</span><div><h2>Publish a classified</h2><p>Put something good on the map.</p></div></div><form class="stack-form" action="/?page=admin" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form_action" value="create_classified"><label>Classified menu<select name="menu_id" required><?php foreach ($adminCategories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= h($category['name']) ?></option><?php endforeach; ?></select></label><label>Listing title<input name="title" maxlength="160" placeholder="What are you sharing?" required></label><label>Description<textarea name="description" rows="4" placeholder="Share the details that matter." required></textarea></label><div class="form-row"><label>Price<input type="number" name="price" min="0" step="0.01" placeholder="0.00" required></label><label>Neighborhood<input name="location" maxlength="120" placeholder="e.g. Riverside"></label></div><label>Image URL<input type="url" name="image_url" placeholder="https://..." required></label><label class="check-row"><input type="checkbox" name="is_featured" value="1"> Mark as a neighbor pick</label><label class="check-row"><input type="checkbox" name="is_premium" value="1"> Show in premium picks for this menu</label><button class="primary-button" type="submit">Publish classified <span>↗</span></button></form></section></div>
            <?php if ($database): ?><section class="recent-listings"><div class="section-head"><h2>Recently published</h2><span>Latest from the market</span></div><?php foreach (array_slice($catalog['items'], 0, 5) as $recent): ?><a href="/?page=listing&amp;id=<?= (int) $recent['id'] ?>&amp;category=<?= rawurlencode((string) $recent['category_slug']) ?>"><span><?= h($recent['title']) ?></span><small><?= h($recent['category_name']) ?> · $<?= number_format((float) $recent['price'], 2) ?></small><span>↗</span></a><?php endforeach; ?></section><?php endif; ?>
            </section>
        <?php elseif ($page === 'listing'): ?>
            <?php if ($listing): ?><article class="detail-page"><a class="back-link" href="/?category=<?= rawurlencode((string) $listing['category_slug']) ?>&amp;location_id=<?= $selectedLocationId ?>">← Back to <?= h($listing['category_name']) ?></a><div class="detail-image-wrap"><img src="<?= h($listing['image_url']) ?>" alt="<?= h($listing['title']) ?>" class="detail-image"><span class="detail-category"><?= h($listing['category_name']) ?></span></div><div class="detail-copy"><div><span class="eyebrow"><?= h($listing['location'] ?: 'A good find nearby') ?></span><h1><?= h($listing['title']) ?></h1><p><?= nl2br(h($listing['description'])) ?></p></div><aside class="detail-price"><span>ASKING PRICE</span><strong>$<?= number_format((float) $listing['price'], 2) ?></strong><a href="/?page=login" class="primary-button">Contact the neighbor <span>↗</span></a><small>Be kind. Meet in a public place.</small></aside></div></article><?php else: ?><section class="empty-state"><span class="empty-spark">✳</span><h1>This find has moved on.</h1><p>Try another shelf in the neighborhood.</p><a class="primary-button" href="/?location_id=<?= $selectedLocationId ?>">Browse the market ↗</a></section><?php endif; ?>
        <?php else: ?>
            <?php $heading = $selectedCategory['name'] ?? ($searchQuery !== '' ? 'Your next good find' : 'The good stuff, nearby'); $subheading = $selectedCategory['description'] ?? ($searchQuery !== '' ? 'A few things from your search, found close to home.' : 'Fresh finds from people just around the corner.'); ?>
            <section class="page-heading catalog-heading"><span class="eyebrow"><span class="live-dot"></span> THE NEIGHBORHOOD MARKETPLACE</span><h1><?= h($heading) ?><span class="heading-period">.</span></h1><p><?= h($subheading) ?></p><div class="heading-meta"><span><strong><?= number_format((int) $catalog['total']) ?></strong> good finds</span><span class="meta-separator">·</span><span>Closer than you think</span></div></section>
            <section class="listing-feed" data-listing-feed data-offset="<?= $visibleCount ?>" data-total="<?= (int) $catalog['total'] ?>" data-category="<?= h($categorySlug) ?>" data-query="<?= h($searchQuery) ?>" data-location-id="<?= $selectedLocationId ?>" aria-label="Classified listings">
                <?php if ($catalog['items']): foreach ($catalog['items'] as $listingItem): ?>
                    <article class="listing-card" data-tilt><a class="listing-photo" href="/?page=listing&amp;id=<?= (int) $listingItem['id'] ?>&amp;category=<?= rawurlencode((string) $listingItem['category_slug']) ?>&amp;location_id=<?= $selectedLocationId ?>"><img src="<?= h($listingItem['image_url']) ?>" alt="<?= h($listingItem['title']) ?>" loading="lazy" decoding="async"><?php if ($listingItem['is_featured']): ?><span class="photo-badge">NEIGHBOR PICK</span><?php endif; ?></a><div class="listing-copy"><div class="listing-topline"><a href="/?category=<?= rawurlencode((string) $listingItem['category_slug']) ?>&amp;location_id=<?= $selectedLocationId ?>" class="listing-category"><?= h($listingItem['category_name']) ?></a><span class="listing-location">⌖ <?= h($listingItem['location'] ?: 'Nearby') ?></span></div><a class="listing-title-link" href="/?page=listing&amp;id=<?= (int) $listingItem['id'] ?>&amp;category=<?= rawurlencode((string) $listingItem['category_slug']) ?>&amp;location_id=<?= $selectedLocationId ?>"><h2><?= h($listingItem['title']) ?></h2></a><p class="listing-description"><?= h($listingItem['description']) ?></p><div class="listing-bottom"><strong class="listing-price">$<?= number_format((float) $listingItem['price'], 2) ?></strong><a class="more-link" href="/?page=listing&amp;id=<?= (int) $listingItem['id'] ?>&amp;category=<?= rawurlencode((string) $listingItem['category_slug']) ?>&amp;location_id=<?= $selectedLocationId ?>">See the details <span>↗</span></a></div></div><span class="card-side-mark" aria-hidden="true"></span></article>
                <?php endforeach; else: ?><div class="empty-state"><span class="empty-spark">✳</span><h2>No finds on this shelf yet.</h2><p>Try a different search. The good stuff moves around.</p><a class="primary-button" href="/?location_id=<?= $selectedLocationId ?>">See everything ↗</a></div><?php endif; ?>
            </section>
            <div class="feed-loader" data-feed-loader <?= $visibleCount >= (int) $catalog['total'] ? 'hidden' : '' ?>><span class="loader-ring"></span><span>Finding a few more good things...</span></div>
            <div class="feed-end" data-feed-end <?= $visibleCount < (int) $catalog['total'] ? 'hidden' : '' ?>><span>✳</span> That's the whole neighborhood for now.</div>
            <div class="feed-sentinel" data-feed-sentinel aria-hidden="true"></div>
        <?php endif; ?>
    </main>
    <aside class="right-rail" aria-label="Premium classifieds">
        <div class="premium-heading"><span class="premium-star">✳</span><div><span class="eyebrow">A LITTLE EXTRA LOVE</span><h2><?= $selectedCategory ? 'Premium ' . h($selectedCategory['name']) . ' picks' : 'Premium picks' ?></h2></div><span class="premium-live">LIVE</span></div>
        <div class="premium-list">
            <?php foreach ($premiumListings as $premium): ?><a class="premium-card" href="/?page=listing&amp;id=<?= (int) $premium['id'] ?>&amp;category=<?= rawurlencode((string) ($selectedCategory['slug'] ?? $premium['category_slug'])) ?>&amp;location_id=<?= $selectedLocationId ?>" data-tilt><div class="premium-image"><img src="<?= h($premium['image_url']) ?>" alt="" loading="lazy" decoding="async"><span class="premium-ribbon">GOOD FIND</span></div><div class="premium-copy"><span class="premium-place"><?= h($premium['location'] ?: 'Around here') ?></span><h3><?= h($premium['title']) ?></h3><div><strong>$<?= number_format((float) $premium['price'], 2) ?></strong><span aria-hidden="true">↗</span></div></div></a><?php endforeach; ?>
        </div>
        <?php if ($premiumListings): ?><a class="premium-all" href="<?= $selectedCategory ? '/?category=' . rawurlencode((string) $selectedCategory['slug']) . '&amp;location_id=' . $selectedLocationId : '/?location_id=' . $selectedLocationId ?>">See what's around <span>↗</span></a><?php else: ?><p class="premium-empty"><?= $selectedCategory ? 'No premium ' . h((string) $selectedCategory['name']) . ' listings yet.' : 'No premium listings just yet.' ?></p><?php endif; ?>
        <div class="rail-quote"><span class="quote-mark">“</span><p>One neighbor's<br>unused thing is<br>another's next favorite.</p><span class="quote-byline">THE GOOD-STUFF CYCLE</span></div>
    </aside>
</div>
<footer class="site-footer"><div class="footer-main"><a class="brand footer-brand" href="/"><span class="brand-mark">cg</span><span class="brand-copy"><strong>common ground</strong><small>the neighborhood marketplace</small></span></a><p>Good finds, good neighbors.<br>That's the whole idea.</p><div class="footer-links"><a href="/">Browse the market</a><a href="/?page=register">Join the neighborhood</a><a href="/?page=login">Sign in</a></div><div class="footer-note"><span class="footer-spark">✳</span><span>Made for the<br>neighbors next door.</span></div></div><div class="footer-bottom"><span>© <?= date('Y') ?> Common Ground Market</span><span>Buy nearby. Belong everywhere.</span><a href="#top" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;">Back to top ↑</a></div></footer>
</body>
</html>
