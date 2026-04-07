<?php
// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Définir le titre de la page
$pageTitle = 'Détails du bulletin';

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Initialiser la variable d'erreur
$_SESSION['error'] = $_SESSION['error'] ?? '';

// Vérifier les paramètres
$eleve_id = isset($_GET['eleve_id']) ? (int)$_GET['eleve_id'] : 0;
$classe_id = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : 0;
$periode_id = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;

if ($eleve_id <= 0 || $classe_id <= 0 || $periode_id <= 0) {
    $_SESSION['error'] = "Paramètres manquants ou invalides.";
    header('Location: bulletins.php');
    exit();
}

try {
    // Initialiser la connexion à la base de données
    $db = new PDO("mysql:host=localhost;dbname=isset_tsevie", 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupérer les informations de l'élève
    $stmt = $db->prepare("SELECT * FROM eleves WHERE id = ? AND classe_id = ?");
    $stmt->execute([$eleve_id, $classe_id]);
    $eleve = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$eleve) {
        throw new Exception("Élève non trouvé dans cette classe.");
    }

    // Récupérer les informations de la classe
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$classe_id]);
    $classe = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$classe) {
        throw new Exception("Classe non trouvée.");
    }

    // Récupérer les informations de la période
    $stmt = $db->prepare("SELECT * FROM periodes WHERE id = ?");
    $stmt->execute([$periode_id]);
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$periode) {
        throw new Exception("Période non trouvée.");
    }

    // Déterminer le semestre
    $semestre = 1;
    if (preg_match('/2|deuxi[eè]me|second/i', $periode['nom'])) {
        $semestre = 2;
    }

    // Récupérer les notes de l'élève pour la période
    // Récupérer d'abord les notes avec les informations de base
    $query = "
        SELECT 
            n.*, 
            m.nom as matiere_nom,
            m.coefficient,
            (COALESCE(n.interro1, 0) + COALESCE(n.interro2, 0) + COALESCE(n.devoir, 0) + (COALESCE(n.compo, 0) * 2)) / 
            NULLIF((CASE WHEN n.interro1 IS NOT NULL THEN 1 ELSE 0 END + 
                   CASE WHEN n.interro2 IS NOT NULL THEN 1 ELSE 0 END + 
                   CASE WHEN n.devoir IS NOT NULL THEN 1 ELSE 0 END + 
                   CASE WHEN n.compo IS NOT NULL THEN 2 ELSE 0 END), 0) as moyenne
        FROM notes n
        JOIN matieres m ON n.matiere_id = m.id
        WHERE n.eleve_id = ? 
        AND n.classe_id = ?
        AND n.semestre = ?
        ORDER BY m.nom
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([$eleve_id, $classe_id, $semestre]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log pour déboguer les matières dans les notes
    error_log("Matières dans les notes : " . print_r(array_column($notes, 'matiere_id'), true));
    
    // Récupérer les professeurs pour chaque matière
    try {
        // Vérifier la structure de la table enseignements
        $structure = $db->query("SHOW COLUMNS FROM enseignements")->fetchAll(PDO::FETCH_COLUMN);
        error_log("Structure de la table enseignements: " . print_r($structure, true));
        
        // Vérifier les données dans la table enseignements
        $test_query = $db->query("SELECT * FROM enseignements LIMIT 5");
        $test_data = $test_query->fetchAll(PDO::FETCH_ASSOC);
        error_log("Données d'exemple de la table enseignements: " . print_r($test_data, true));
        
        // Récupérer tous les enseignements avec les noms des professeurs
        $query_profs = "
            SELECT e.id, e.matiere_id, e.professeur_id, CONCAT(u.prenom, ' ', u.nom) as professeur_nom
            FROM enseignements e
            JOIN utilisateurs u ON e.professeur_id = u.id
        ";
        
        $professeurs = [];
        $stmt = $db->query($query_profs);
        $all_profs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Tous les enseignements avec professeurs: " . print_r($all_profs, true));
        
        // Créer un tableau associatif matiere_id => professeur_nom
        foreach ($all_profs as $row) {
            $professeurs[$row['matiere_id']] = $row['professeur_nom'];
        }
        
        error_log("Professeurs récupérés : " . print_r($professeurs, true));
        
        // Associer chaque professeur à sa matière dans les notes
        foreach ($notes as &$note) {
            $matiere_id = $note['matiere_id'];
            $note['professeur_nom'] = $professeurs[$matiere_id] ?? 'Non attribué';
            error_log(sprintf("Matière ID: %d, Nom: %s, Professeur: %s", 
                $matiere_id, 
                $note['matiere_nom'] ?? 'Inconnu', 
                $note['professeur_nom']));
        }
        unset($note);
        
    } catch (Exception $e) {
        error_log("Erreur lors de la récupération des professeurs: " . $e->getMessage());
        error_log("Notes avant l'erreur : " . print_r($notes, true));
        error_log("Professeurs avant l'erreur : " . print_r($professeurs, true));
        // En cas d'erreur, on garde 'Non attribué' comme valeur par défaut
        foreach ($notes as &$note) {
            $note['professeur_nom'] = 'Non attribué';
        }
        unset($note);
    }

    // Calculer les rangs par matière
    foreach ($notes as &$note) {
        $matiere_id = $note['matiere_id'];
        $moyenne_eleve = $note['moyenne'];
        
        // Récupérer toutes les moyennes de la classe pour cette matière
        $query_rang = "
            SELECT 
                e.id,
                (COALESCE(n.interro1, 0) + COALESCE(n.interro2, 0) + COALESCE(n.devoir, 0) + (COALESCE(n.compo, 0) * 2)) / 
                NULLIF((CASE WHEN n.interro1 IS NOT NULL THEN 1 ELSE 0 END + 
                       CASE WHEN n.interro2 IS NOT NULL THEN 1 ELSE 0 END + 
                       CASE WHEN n.devoir IS NOT NULL THEN 1 ELSE 0 END + 
                       CASE WHEN n.compo IS NOT NULL THEN 2 ELSE 0 END), 0) as moyenne
            FROM notes n
            JOIN eleves e ON n.eleve_id = e.id
            WHERE n.matiere_id = ? 
            AND n.classe_id = ?
            AND n.semestre = ?
            AND (n.interro1 IS NOT NULL OR n.interro2 IS NOT NULL OR n.devoir IS NOT NULL OR n.compo IS NOT NULL)
        ";
        
        $stmt_rang = $db->prepare($query_rang);
        $stmt_rang->execute([$matiere_id, $classe_id, $semestre]);
        $toutes_les_moyennes = $stmt_rang->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculer le rang
        $rang = 1;
        foreach ($toutes_les_moyennes as $autre) {
            if ($autre['id'] != $eleve_id) {
                $moyenne_autre = $autre['moyenne'];
                if ($moyenne_autre > $moyenne_eleve) {
                    $rang++;
                }
            }
        }
        
        $note['rang_matiere'] = $rang;
    }
    unset($note);

    // Préparer les données pour le template
    $bulletins = [];
    $moyenne_generale = 0;
    $total_coeff = 0;
    $has_notes = false;

    foreach ($notes as $note) {
        $moyenne = $note['moyenne'] ?? 0;
        
        if ($moyenne > 0) {
            $has_notes = true;
            $moyenne_ponderee = $moyenne * $note['coefficient'];
            $moyenne_generale += $moyenne_ponderee;
            $total_coeff += $note['coefficient'];
        }
        
        // Générer l'appréciation pour cette matière
        $appreciation = '';
        if ($moyenne >= 16) {
            $appreciation = 'Très Bien';
        } elseif ($moyenne >= 14) {
            $appreciation = 'Bien';
        } elseif ($moyenne >= 12) {
            $appreciation = 'Assez Bien';
        } elseif ($moyenne >= 10) {
            $appreciation = 'Passable';
        } elseif ($moyenne > 0) {
            $appreciation = 'Insuffisant';
        }
        
        $bulletins[] = [
            'matiere_id' => $note['matiere_id'],
            'matiere_nom' => $note['matiere_nom'],
            'coefficient' => $note['coefficient'],
            'interro1' => $note['interro1'] ?? null,
            'interro2' => $note['interro2'] ?? null,
            'devoir' => $note['devoir'] ?? null,
            'compo' => $note['compo'] ?? null,
            'moyenne' => $moyenne,
            'professeur' => $note['professeur_nom'] ?? 'Non attribué',
            'appreciation' => $appreciation,
            'rang_matiere' => $note['rang_matiere'] ?? 0
        ];
    }

    // Calculer la moyenne générale
    $moyenne_generale = $has_notes && $total_coeff > 0 ? $moyenne_generale / $total_coeff : 0;

    // Fonction pour générer une appréciation basée sur la moyenne
    function getAppreciation($moyenne) {
        if ($moyenne >= 16) return 'Très Bien';
        if ($moyenne >= 14) return 'Bien';
        if ($moyenne >= 12) return 'Assez Bien';
        if ($moyenne >= 10) return 'Passable';
        if ($moyenne > 0) return 'Insuffisant';
        return '';
    }
    
    // Ajouter l'appréciation à chaque matière
    foreach ($notes as &$note) {
        $note['appreciation'] = $note['moyenne'] ? getAppreciation($note['moyenne']) : '';
    }
    unset($note);
    
    // Appréciation générale
    $appreciation_generale = $moyenne_generale ? getAppreciation($moyenne_generale) : '';

    // Récupérer l'effectif de la classe pour le rang
    $stmt = $db->prepare("SELECT COUNT(*) as effectif FROM eleves WHERE classe_id = ?");
    $stmt->execute([$classe_id]);
    $effectif = $stmt->fetch(PDO::FETCH_ASSOC)['effectif'];

    // Calculer le rang avec formatage
    $rang = 'N/A';
    if ($has_notes) {
        $stmt = $db->prepare("
            SELECT COUNT(*) + 1 as rang
            FROM (
                SELECT e.id, 
                       AVG((COALESCE(n.interro1, 0) + COALESCE(n.interro2, 0) + COALESCE(n.devoir, 0) + (COALESCE(n.compo, 0) * 2)) / 
                           NULLIF((CASE WHEN n.interro1 IS NOT NULL THEN 1 ELSE 0 END + 
                                  CASE WHEN n.interro2 IS NOT NULL THEN 1 ELSE 0 END + 
                                  CASE WHEN n.devoir IS NOT NULL THEN 1 ELSE 0 END + 
                                  CASE WHEN n.compo IS NOT NULL THEN 2 ELSE 0 END), 0)) as moyenne
                FROM eleves e
                JOIN notes n ON n.eleve_id = e.id
                WHERE e.classe_id = ? AND n.semestre = ?
                GROUP BY e.id
                HAVING AVG((COALESCE(n.interro1, 0) + COALESCE(n.interro2, 0) + COALESCE(n.devoir, 0) + (COALESCE(n.compo, 0) * 2)) / 
                          NULLIF((CASE WHEN n.interro1 IS NOT NULL THEN 1 ELSE 0 END + 
                                 CASE WHEN n.interro2 IS NOT NULL THEN 1 ELSE 0 END + 
                                 CASE WHEN n.devoir IS NOT NULL THEN 1 ELSE 0 END + 
                                 CASE WHEN n.compo IS NOT NULL THEN 2 ELSE 0 END), 0)) > ?
            ) as t
        
        ");
        $stmt->execute([$classe_id, $semestre, $moyenne_generale]);
        $rang_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $rang = $rang_data ? $rang_data['rang'] . ($rang_data['rang'] == 1 ? 'er' : 'e') : 1;
    }

    // Inclure le header après avoir traité toutes les données
    include __DIR__ . '/includes/header.php';

    // Ajouter les informations de l'école
    $ecole = [
        'nom' => 'Groupe Scolaire ISSET',
        'ville' => 'Tsévié',
        'pays' => 'TOGO',
        'adresse' => 'BP 200 Tsévié',
        'contact' => '22 50 00 00',
        'email' => 'contact@isset.tg'
    ];
    
    // Inclure le template du bulletin
    include __DIR__ . '/templates/bulletin_template.php';

} catch (Exception $e) {
    // En cas d'erreur, afficher le message d'erreur directement
    $error_message = "Erreur : " . $e->getMessage();
    include __DIR__ . '/includes/header.php';
    echo '<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">';
    echo '    <p class="font-bold">Erreur</p>';
    echo '    <p>' . htmlspecialchars($error_message) . '</p>';
    echo '</div>';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Inclure le footer
include __DIR__ . '/includes/footer.php';
?>
