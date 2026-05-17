<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function connexion_bdd(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

function reponse_json(array $donnees, int $code_statut = 200): void
{
    http_response_code($code_statut);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

function valeur_requete(string $cle, $valeur_defaut = null)
{
    global $donnees_requete;

    if (is_array($donnees_requete) && array_key_exists($cle, $donnees_requete)) {
        return $donnees_requete[$cle];
    }

    return $_POST[$cle] ?? $valeur_defaut;
}

function normaliser_tache(?array $tache): ?array
{
    if (!$tache) {
        return null;
    }

    return [
        'id'                => (int)$tache['id'],
        'utilisateur_id'    => (int)$tache['utilisateur_id'],
        'titre'             => $tache['titre'],
        'description'       => $tache['description'],
        'echeance'          => $tache['echeance'],
        'priorite'          => $tache['priorite'],
        'est_terminee'      => (int)$tache['est_terminee'],
        'date_creation'     => $tache['date_creation'],
        'date_modification' => $tache['date_modification'] ?? null,
    ];
}

if (!isset($_SESSION['utilisateur_id'])) {
    reponse_json([
        'succes' => false,
        'erreur' => 'Non authentifié',
    ], 401);
}

$utilisateur_id = (int)$_SESSION['utilisateur_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$methode_requete = $_SERVER['REQUEST_METHOD'];

if ($action === null) {
    reponse_json([
        'succes' => false,
        'erreur' => 'Action manquante',
    ], 400);
}

$donnees_requete = [];
if (in_array($methode_requete, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $corps_requete = file_get_contents('php://input');
    if ($corps_requete !== '') {
        $donnees_decodees = json_decode($corps_requete, true);
        $donnees_requete = is_array($donnees_decodees) ? $donnees_decodees : [];
    }
}

$pdo = connexion_bdd();

try {
    switch ($action) {
        case 'lister_taches':
            $page_actuelle = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $parametre_par_page = $_GET['par_page'] ?? null;

            if ($parametre_par_page === 'toutes') {
                $par_page = null;
                $_SESSION['par_page'] = 'toutes';
            } elseif ($parametre_par_page !== null) {
                $par_page = max(1, (int)$parametre_par_page);
                $_SESSION['par_page'] = $par_page;
            } else {
                $session_par_page = $_SESSION['par_page'] ?? 10;
                $par_page = $session_par_page === 'toutes' ? null : max(1, (int)$session_par_page);
            }

            $requete = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE utilisateur_id = :utilisateur_id");
            $requete->execute(['utilisateur_id' => $utilisateur_id]);
            $total_taches = (int)$requete->fetchColumn();

            if ($par_page === null) {
                $nombre_pages = 1;
                $decalage = 0;
            } else {
                $nombre_pages = max(1, (int)ceil($total_taches / $par_page));
                $page_actuelle = min($page_actuelle, $nombre_pages);
                $decalage = ($page_actuelle - 1) * $par_page;
            }

            if ($par_page === null) {
                $requete = $pdo->prepare("
                    SELECT * FROM tasks
                    WHERE utilisateur_id = :utilisateur_id
                    ORDER BY date_creation DESC
                ");
                $requete->execute(['utilisateur_id' => $utilisateur_id]);
            } else {
                $requete = $pdo->prepare("
                    SELECT * FROM tasks
                    WHERE utilisateur_id = :utilisateur_id
                    ORDER BY date_creation DESC
                    LIMIT :limite OFFSET :decalage
                ");
                $requete->bindValue(':utilisateur_id', $utilisateur_id, PDO::PARAM_INT);
                $requete->bindValue(':limite', $par_page, PDO::PARAM_INT);
                $requete->bindValue(':decalage', $decalage, PDO::PARAM_INT);
                $requete->execute();
            }

            $taches = array_map('normaliser_tache', $requete->fetchAll());

            reponse_json([
                'succes'  => true,
                'donnees' => [
                    'taches'     => $taches,
                    'pagination' => [
                        'page'         => $page_actuelle,
                        'nombre_pages' => $nombre_pages,
                        'total_taches' => $total_taches,
                        'par_page'     => $par_page === null ? 'toutes' : $par_page,
                    ],
                ],
            ]);

        case 'creer_tache':
            if ($methode_requete !== 'POST') {
                reponse_json(['succes' => false, 'erreur' => 'Méthode non autorisée'], 405);
            }

            $titre = trim((string)valeur_requete('titre', ''));
            $description = trim((string)valeur_requete('description', ''));
            $echeance = valeur_requete('echeance', null);
            $priorite = valeur_requete('priorite', 'normale');

            if ($titre === '') {
                reponse_json(['succes' => false, 'erreur' => 'Le titre est obligatoire'], 400);
            }

            if (!in_array($priorite, ['basse', 'normale', 'haute'], true)) {
                $priorite = 'normale';
            }

            if ($echeance === '') {
                $echeance = null;
            }

            $requete = $pdo->prepare("
                INSERT INTO tasks (utilisateur_id, titre, description, echeance, priorite, est_terminee, date_creation)
                VALUES (:utilisateur_id, :titre, :description, :echeance, :priorite, 0, NOW())
            ");
            $requete->execute([
                'utilisateur_id' => $utilisateur_id,
                'titre'          => $titre,
                'description'    => $description !== '' ? $description : null,
                'echeance'       => $echeance,
                'priorite'       => $priorite,
            ]);

            $nouvel_id = (int)$pdo->lastInsertId();
            $requete = $pdo->prepare("SELECT * FROM tasks WHERE id = :id AND utilisateur_id = :utilisateur_id");
            $requete->execute(['id' => $nouvel_id, 'utilisateur_id' => $utilisateur_id]);

            reponse_json([
                'succes'  => true,
                'donnees' => normaliser_tache($requete->fetch()),
            ], 201);

        case 'modifier_tache':
            if ($methode_requete !== 'PUT' && $methode_requete !== 'POST') {
                reponse_json(['succes' => false, 'erreur' => 'Méthode non autorisée'], 405);
            }

            $id = (int)valeur_requete('id', 0);
            if ($id <= 0) {
                reponse_json(['succes' => false, 'erreur' => 'Identifiant de tâche invalide'], 400);
            }

            $requete = $pdo->prepare("SELECT * FROM tasks WHERE id = :id AND utilisateur_id = :utilisateur_id");
            $requete->execute(['id' => $id, 'utilisateur_id' => $utilisateur_id]);
            $tache = $requete->fetch();

            if (!$tache) {
                reponse_json(['succes' => false, 'erreur' => 'Tâche introuvable'], 404);
            }

            $titre = trim((string)valeur_requete('titre', $tache['titre']));
            $description = trim((string)valeur_requete('description', $tache['description']));
            $echeance = valeur_requete('echeance', $tache['echeance']);
            $priorite = valeur_requete('priorite', $tache['priorite']);

            if ($titre === '') {
                reponse_json(['succes' => false, 'erreur' => 'Le titre est obligatoire'], 400);
            }

            if (!in_array($priorite, ['basse', 'normale', 'haute'], true)) {
                $priorite = 'normale';
            }

            if ($echeance === '') {
                $echeance = null;
            }

            $requete = $pdo->prepare("
                UPDATE tasks
                SET titre = :titre,
                    description = :description,
                    echeance = :echeance,
                    priorite = :priorite,
                    date_modification = NOW()
                WHERE id = :id AND utilisateur_id = :utilisateur_id
            ");
            $requete->execute([
                'titre'          => $titre,
                'description'    => $description !== '' ? $description : null,
                'echeance'       => $echeance,
                'priorite'       => $priorite,
                'id'             => $id,
                'utilisateur_id' => $utilisateur_id,
            ]);

            $requete = $pdo->prepare("SELECT * FROM tasks WHERE id = :id AND utilisateur_id = :utilisateur_id");
            $requete->execute(['id' => $id, 'utilisateur_id' => $utilisateur_id]);

            reponse_json([
                'succes'  => true,
                'donnees' => normaliser_tache($requete->fetch()),
            ]);

        case 'basculer_statut_tache':
            if ($methode_requete !== 'POST' && $methode_requete !== 'PATCH') {
                reponse_json(['succes' => false, 'erreur' => 'Méthode non autorisée'], 405);
            }

            $id = (int)valeur_requete('id', 0);
            if ($id <= 0) {
                reponse_json(['succes' => false, 'erreur' => 'Identifiant de tâche invalide'], 400);
            }

            $requete = $pdo->prepare("SELECT est_terminee FROM tasks WHERE id = :id AND utilisateur_id = :utilisateur_id");
            $requete->execute(['id' => $id, 'utilisateur_id' => $utilisateur_id]);
            $tache = $requete->fetch();

            if (!$tache) {
                reponse_json(['succes' => false, 'erreur' => 'Tâche introuvable'], 404);
            }

            $nouveau_statut = (int)$tache['est_terminee'] === 1 ? 0 : 1;
            $requete = $pdo->prepare("
                UPDATE tasks
                SET est_terminee = :statut,
                    date_modification = NOW()
                WHERE id = :id AND utilisateur_id = :utilisateur_id
            ");
            $requete->execute([
                'statut'         => $nouveau_statut,
                'id'             => $id,
                'utilisateur_id' => $utilisateur_id,
            ]);

            reponse_json([
                'succes'  => true,
                'donnees' => [
                    'id'           => $id,
                    'est_terminee' => $nouveau_statut,
                ],
            ]);

        case 'supprimer_tache':
            if ($methode_requete !== 'DELETE' && $methode_requete !== 'POST') {
                reponse_json(['succes' => false, 'erreur' => 'Méthode non autorisée'], 405);
            }

            $id = (int)valeur_requete('id', 0);
            if ($id <= 0) {
                reponse_json(['succes' => false, 'erreur' => 'Identifiant de tâche invalide'], 400);
            }

            $requete = $pdo->prepare("DELETE FROM tasks WHERE id = :id AND utilisateur_id = :utilisateur_id");
            $requete->execute(['id' => $id, 'utilisateur_id' => $utilisateur_id]);

            if ($requete->rowCount() === 0) {
                reponse_json(['succes' => false, 'erreur' => 'Tâche introuvable'], 404);
            }

            reponse_json([
                'succes'  => true,
                'donnees' => ['id' => $id],
            ]);

        default:
            reponse_json([
                'succes' => false,
                'erreur' => 'Action inconnue',
            ], 400);
    }
} catch (PDOException $exception) {
    reponse_json([
        'succes' => false,
        'erreur' => 'Erreur serveur',
    ], 500);
}
