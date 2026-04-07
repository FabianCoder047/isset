<?php
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json');

// Vérifier si la requête est de type POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

try {
    // Récupérer les données du formulaire
    $professeur_id = isset($_POST['professeur_id']) ? (int)$_POST['professeur_id'] : 0;
    $matiere_id = isset($_POST['matiere_id']) ? (int)$_POST['matiere_id'] : 0;
    $classe_id = isset($_POST['classe_id']) ? (int)$_POST['classe_id'] : 0;

    // Validation des données
    if ($professeur_id <= 0 || $matiere_id <= 0 || $classe_id <= 0) {
        throw new Exception('Données invalides');
    }

    // Vérifier si cette matière est déjà enseignée dans cette classe (par n'importe quel professeur)
    $stmt = $db->prepare("SELECT id, professeur_id FROM enseignements WHERE matiere_id = ? AND classe_id = ?");
    $stmt->execute([$matiere_id, $classe_id]);
    
    if ($stmt->rowCount() > 0) {
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing['professeur_id'] != $professeur_id) {
            // Récupérer le nom du professeur existant pour un message plus clair
            $stmt_prof = $db->prepare("SELECT nom, prenom FROM utilisateurs WHERE id = ?");
            $stmt_prof->execute([$existing['professeur_id']]);
            $prof_info = $stmt_prof->fetch(PDO::FETCH_ASSOC);
            $prof_name = $prof_info ? $prof_info['prenom'] . ' ' . $prof_info['nom'] : 'un autre professeur';
            throw new Exception("Cette matière est déjà enseignée par $prof_name dans cette classe");
        } else {
            throw new Exception('Cette affectation existe déjà');
        }
    }

    // Ajouter la nouvelle affectation
    $stmt = $db->prepare("INSERT INTO enseignements (professeur_id, matiere_id, classe_id) VALUES (?, ?, ?)");
    $result = $stmt->execute([$professeur_id, $matiere_id, $classe_id]);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Erreur lors de l\'ajout de l\'affectation');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
