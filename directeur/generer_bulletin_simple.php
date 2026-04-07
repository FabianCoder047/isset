<?php
// Version simple pour téléchargement individuel

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';

// Désactiver les erreurs
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Classe PDF personnalisée avec filigrane
class PDF_Bulletin extends TCPDF {
    public function addWatermark() {
        // Filigrane avec le logo
        $logo_file = __DIR__ . '/../images/logo.jpeg';
        
        // Debug pour vérifier si le fichier existe
        error_log("Recherche du logo: " . $logo_file);
        error_log("Fichier existe: " . (file_exists($logo_file) ? "OUI" : "NON"));
        
        if (file_exists($logo_file)) {
            // Obtenir les dimensions de la page
            $page_width = $this->getPageWidth();
            $page_height = $this->getPageHeight();
            
            // Taille du filigrane (plus grand et plus visible)
            $watermark_width = 150;
            $watermark_height = 150;
            
            // Centrer le logo
            $x = ($page_width - $watermark_width) / 2;
            $y = ($page_height - $watermark_height) / 2;
            
            // Ajouter le logo en filigrane avec transparence
            $this->SetAlpha(0.15); // Transparence modérée (15% d'opacité)
            $this->Image($logo_file, $x, $y, $watermark_width, $watermark_height, 'JPEG', '', 'C', false, 300, '', false, false, 0);
            $this->SetAlpha(1); // Remettre l'opacité normale
            error_log("Logo ajouté avec succès");
        } else {
            error_log("Logo non trouvé !");
        }
    }
}

// Vérifier les paramètres
$eleve_id = isset($_GET['eleve_id']) ? (int)$_GET['eleve_id'] : 0;
$classe_id = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : 0;
$periode_id = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;

if ($eleve_id <= 0 || $classe_id <= 0 || $periode_id <= 0) {
    die("Paramètres invalides");
}

try {
    $db = new PDO("mysql:host=localhost;dbname=isset_tsevie", 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Récupérer l'élève
    $stmt = $db->prepare("SELECT * FROM eleves WHERE id = ?");
    $stmt->execute([$eleve_id]);
    $eleve = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$eleve) {
        die("Élève non trouvé");
    }
    
    // Récupérer la classe
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$classe_id]);
    $classe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer la période
    $stmt = $db->prepare("SELECT * FROM periodes WHERE id = ?");
    $stmt->execute([$periode_id]);
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Déterminer le semestre
    $semestre = 1;
    if (preg_match('/2|deuxi[eè]me|second/i', $periode['nom'])) {
        $semestre = 2;
    }
    
    // Récupérer les notes
    $stmt = $db->prepare("
        SELECT n.*, m.nom as matiere_nom, m.coefficient,
               (n.interro1 + n.interro2 + n.devoir + (n.compo * 2)) / 5 as moyenne,
               COALESCE(u.prenom, '') as professeur_prenom,
               COALESCE(u.nom, '') as professeur_nom
        FROM notes n
        JOIN matieres m ON n.matiere_id = m.id
        LEFT JOIN enseignements e ON e.matiere_id = m.id
        LEFT JOIN utilisateurs u ON e.professeur_id = u.id
        WHERE n.eleve_id = ? AND n.classe_id = ? AND n.semestre = ?
        ORDER BY m.nom
    ");
    $stmt->execute([$eleve_id, $classe_id, $semestre]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Préparer les données pour le template
    $bulletins = [];
    $moyenne_generale = 0;
    $total_coeff = 0;
    
    foreach ($notes as $note) {
        $moyenne = ($note['interro1'] + $note['interro2'] + $note['devoir'] + $note['compo']) / 4;
        // Garder plus de précision pour éviter les erreurs d'arrondi
        $moyenne_precise = $moyenne;
        $moyenne_affichee = round($moyenne, 2);
        
        // Calculer le rang dans cette matière pour cet élève
        $stmt_rang_matiere = $db->prepare("
            SELECT COUNT(*) as nb_meilleurs, 
                   (SELECT COUNT(*) FROM notes n2 
                    WHERE n2.matiere_id = ? AND n2.classe_id = ? AND n2.semestre = ?
                    AND ((n2.interro1 + n2.interro2 + n2.devoir + n2.compo) / 4) > 0) as total_eleves
            FROM notes n
            WHERE n.matiere_id = ? AND n.classe_id = ? AND n.semestre = ?
            AND ((n.interro1 + n.interro2 + n.devoir + n.compo) / 4) > ?
        ");
        $stmt_rang_matiere->execute([$note['matiere_id'], $classe_id, $semestre, $note['matiere_id'], $classe_id, $semestre, $moyenne_precise]);
        $rang_matiere_data = $stmt_rang_matiere->fetch(PDO::FETCH_ASSOC);
        
        if ($rang_matiere_data && $rang_matiere_data['total_eleves'] > 1) {
            $rang_matiere = $rang_matiere_data['nb_meilleurs'] + 1;
        } else {
            $rang_matiere = 1; // Seul élève ou pas de comparaison possible
        }
        
        $bulletins[] = [
            'matiere_nom' => $note['matiere_nom'],
            'coefficient' => $note['coefficient'],
            'interro1' => $note['interro1'],
            'interro2' => $note['interro2'],
            'devoir' => $note['devoir'],
            'compo' => $note['compo'],
            'moyenne' => $moyenne_affichee,
            'professeur' => trim($note['professeur_prenom'] . ' ' . $note['professeur_nom']),
            'rang_matiere' => $rang_matiere
        ];
        
        if ($moyenne_precise > 0) {
            $moyenne_generale += $moyenne_precise * $note['coefficient'];
            $total_coeff += $note['coefficient'];
        }
    }
    
    $moyenne_generale_precise = $total_coeff > 0 ? $moyenne_generale / $total_coeff : 0;
    $moyenne_generale_affichee = round($moyenne_generale_precise, 2);
    
    // Récupérer l'effectif réel de la classe
    $stmt_effectif = $db->prepare("SELECT COUNT(*) as effectif FROM eleves WHERE classe_id = ?");
    $stmt_effectif->execute([$classe_id]);
    $effectif_data = $stmt_effectif->fetch(PDO::FETCH_ASSOC);
    $effectif = $effectif_data['effectif'];
    
    // Calculer le rang général avec une méthode simple et correcte
    $rang = 'Non classé';
    if ($moyenne_generale_precise > 0) {
        $stmt_rang = $db->prepare("
            SELECT rang FROM (
                SELECT e.id,
                       ROW_NUMBER() OVER (ORDER BY 
                           SUM((n.interro1 + n.interro2 + n.devoir + n.compo) / 4 * m.coefficient) / SUM(m.coefficient) DESC
                       ) as rang
                FROM eleves e
                JOIN notes n ON e.id = n.eleve_id
                JOIN matieres m ON n.matiere_id = m.id
                WHERE n.classe_id = ? AND n.semestre = ?
                GROUP BY e.id
                HAVING SUM((n.interro1 + n.interro2 + n.devoir + n.compo) / 4 * m.coefficient) / SUM(m.coefficient) > 0
            ) as classement
            WHERE id = ?
        ");
        $stmt_rang->execute([$classe_id, $semestre, $eleve_id]);
        $rang_data = $stmt_rang->fetch(PDO::FETCH_ASSOC);
        $rang = $rang_data ? $rang_data['rang'] : 1;
    }
    
    // Générer l'appréciation en fonction de la moyenne
    $appreciation = '';
    if ($moyenne_generale_affichee >= 16) {
        $appreciation = "Mention Très-Bien";
    } elseif ($moyenne_generale_affichee >= 14) {
        $appreciation = "Mention Bien";
    } elseif ($moyenne_generale_affichee >= 12) {
        $appreciation = "Mention Assez-Bien";
    } elseif ($moyenne_generale_affichee >= 10) {
        $appreciation = "Mention Passable";
    } else {
        $appreciation = "Travail insuffisant";
    }
    
    // Préparer toutes les variables pour le template
    $eleve = $eleve;
    $classe = $classe;
    $periode = $periode;
    $ecole = ['nom' => 'ISSET', 'ville' => 'Tsévié', 'pays' => 'TOGO'];
    $bulletins = $bulletins;
    $moyenne_generale = $moyenne_generale_affichee;
    $rang = $rang;
    $appreciation = $appreciation;
    $effectif = $effectif;
    
    // Créer le PDF avec filigrane
    $pdf = new PDF_Bulletin('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('ISSET Tsévié');
    $pdf->SetAuthor('ISSET Tsévié');
    $pdf->SetTitle('Bulletin de ' . $eleve['nom'] . ' ' . $eleve['prenom']);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->AddPage();
    
    // Utiliser le template avec toutes les variables
    ob_start();
    include __DIR__ . '/templates/bulletin_template.php';
    $html = ob_get_clean();
    
    // Ajouter le HTML au PDF
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Ajouter le filigrane après le contenu
    $pdf->addWatermark();
    
    // Nom du fichier
    $filename = 'Bulletin_' . preg_replace('/[^a-zA-Z0-9]/', '_', $eleve['nom'] . '_' . $eleve['prenom']) . '_' . date('Y-m-d') . '.pdf';
    
    // Nettoyer les buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Envoyer le PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf->Output('', 'S')));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $pdf->Output('', 'S');
    exit;
    
} catch (Exception $e) {
    die("Erreur: " . $e->getMessage());
}
?>
