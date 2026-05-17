<?php
session_start();

require_once __DIR__ . '/config.php';

function recuperer_pdo(): PDO
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$erreurs = [];
$message_succes = null;

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $email = trim($_POST['email'] ?? '');
    $mot_de_passe = $_POST['mot_de_passe'] ?? '';
    $confirmation_mot_de_passe = $_POST['confirmation_mot_de_passe'] ?? '';

    if ($email === '' || $mot_de_passe === '' || $confirmation_mot_de_passe === '') {
        $erreurs[] = "Tous les champs d'inscription sont obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = "L'adresse email n'est pas valide.";
    } elseif ($mot_de_passe !== $confirmation_mot_de_passe) {
        $erreurs[] = "Les mots de passe ne correspondent pas.";
    } else {
        try {
            $pdo = recuperer_pdo();

            $requete = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $requete->execute(['email' => $email]);
            if ($requete->fetch()) {
                $erreurs[] = "Un compte existe déjà avec cette adresse email.";
            } else {
                $hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);
                $requete = $pdo->prepare("INSERT INTO users (email, mot_de_passe_hash, date_creation) VALUES (:email, :hash, NOW())");
                $requete->execute([
                    'email' => $email,
                    'hash'  => $hash,
                ]);
                $message_succes = "Inscription réussie. Vous pouvez maintenant vous connecter.";
            }
        } catch (PDOException $e) {
            $erreurs[] = "Erreur lors de l'inscription.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $mot_de_passe = $_POST['mot_de_passe'] ?? '';

    if ($email === '' || $mot_de_passe === '') {
        $erreurs[] = "Email et mot de passe sont obligatoires pour la connexion.";
    } else {
        try {
            $pdo = recuperer_pdo();
            $requete = $pdo->prepare("SELECT id, mot_de_passe_hash FROM users WHERE email = :email LIMIT 1");
            $requete->execute(['email' => $email]);
            $utilisateur = $requete->fetch();
            if ($utilisateur && password_verify($mot_de_passe, $utilisateur['mot_de_passe_hash'])) {
                
                $_SESSION['utilisateur_id'] = (int)$utilisateur['id'];
                $_SESSION['utilisateur_email'] = $email;
                if (!isset($_SESSION['par_page'])) {
                    $_SESSION['par_page'] = 10;
                }
                header('Location: index.php');
                exit;
            } else {
                $erreurs[] = "Identifiants incorrects.";
            }
        } catch (PDOException $e) {
            $erreurs[] = "Erreur lors de la connexion.";
        }
    }
}

$est_connecte = isset($_SESSION['utilisateur_id']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestionnaire de tâches</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="app-header">
    <h1>Gestionnaire de tâches</h1>
    <?php if ($est_connecte): ?>
        <div class="user-info">
            <span><?php echo e($_SESSION['utilisateur_email'] ?? ''); ?></span>
            <a href="index.php?action=logout" class="btn btn-secondary">Déconnexion</a>
        </div>
    <?php endif; ?>
</header>

<main class="app-main">
    <?php if (!empty($erreurs)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($erreurs as $erreur): ?>
                    <li><?php echo e($erreur); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($message_succes): ?>
        <div class="alert alert-success">
            <?php echo e($message_succes); ?>
        </div>
    <?php endif; ?>

    <?php if (!$est_connecte): ?>
        <section class="auth-container">
            <div class="auth-form">
                <h2>Connexion</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="login_email">Email</label>
                        <input type="email" id="login_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="connexion_mot_de_passe">Mot de passe</label>
                        <input type="password" id="connexion_mot_de_passe" name="mot_de_passe" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary">Se connecter</button>
                </form>
            </div>

            <div class="auth-form">
                <h2>Inscription</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="register_email">Email</label>
                        <input type="email" id="register_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="inscription_mot_de_passe">Mot de passe</label>
                        <input type="password" id="inscription_mot_de_passe" name="mot_de_passe" required>
                    </div>
                    <div class="form-group">
                        <label for="confirmation_mot_de_passe">Confirmer le mot de passe</label>
                        <input type="password" id="confirmation_mot_de_passe" name="confirmation_mot_de_passe" required>
                    </div>
                    <button type="submit" name="register" class="btn btn-secondary">S'inscrire</button>
                </form>
            </div>
        </section>
    <?php else: ?>
        <section class="tasks-container">
            <div class="task-form">
                <h2>Nouvelle tâche</h2>
                <form id="formulaire_tache">
                    <input type="hidden" id="tache_id" name="id">
                    <div class="form-group">
                        <label for="tache_titre">Titre</label>
                        <input type="text" id="tache_titre" name="titre" required>
                    </div>
                    <div class="form-group">
                        <label for="tache_description">Description</label>
                        <textarea id="tache_description" name="description"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="tache_echeance">Date d'échéance</label>
                        <input type="date" id="tache_echeance" name="echeance">
                    </div>
                    <div class="form-group">
                        <label for="tache_priorite">Priorité</label>
                        <select id="tache_priorite" name="priorite">
                            <option value="basse">Basse</option>
                            <option value="normale" selected>Normale</option>
                            <option value="haute">Haute</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Enregistrer la tâche</button>
                    <button type="button" id="annuler_modification_tache" class="btn btn-secondary hidden">Annuler la modification</button>
                </form>
            </div>

            <div class="task-filters">
                <h2>Filtres & tri</h2>
                <div class="filters-row">
                    <div class="form-group">
                        <label for="filtre_statut">Statut</label>
                        <select id="filtre_statut">
                            <option value="toutes">Toutes</option>
                            <option value="en_cours">En cours</option>
                            <option value="terminees">Terminées</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filtre_priorite">Priorité</label>
                        <select id="filtre_priorite">
                            <option value="toutes">Toutes</option>
                            <option value="basse">Basse</option>
                            <option value="normale">Normale</option>
                            <option value="haute">Haute</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="tri_taches">Trier par</label>
                        <select id="tri_taches">
                            <option value="date_creation">Date de création</option>
                            <option value="echeance">Date d'echeance</option>
                            <option value="priorite">Priorité</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="par_page">Résultats par page</label>
                        <select id="par_page">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="toutes">Toutes</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="tasks-list">
                <h2>Liste des tâches</h2>
                <div id="chargement_taches" class="loading hidden">Chargement...</div>
                <div id="erreur_taches" class="alert alert-error hidden"></div>
                <table class="tasks-table">
                    <thead>
                    <tr>
                        <th>Statut</th>
                        <th>Titre</th>
                        <th>Description</th>
                        <th>Priorité</th>
                        <th>Échéance</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody id="corps_taches">
                    
                    </tbody>
                </table>

                <div class="pagination">
                    <button id="pagination_precedent" class="btn btn-secondary" disabled>Précédent</button>
                    <span id="pagination_info"></span>
                    <button id="pagination_suivant" class="btn btn-secondary" disabled>Suivant</button>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>

<script src="app.js?v=3"></script>
</body>
</html>
