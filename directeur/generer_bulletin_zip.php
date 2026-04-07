<?php
// Version ZIP avec filigrane du logo

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';

// Désactiver les erreurs pour ne pas corrompre le ZIP
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Classe PDF personnalisée avec filigrane
class PDF_Bulletin extends TCPDF {
    public function Header() {
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
        }
    }
    
    public function Footer() {
        // Pied de page vide pour ne pas interférer avec le filigrane
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classe_id = (int)($_POST['classe_id'] ?? 0);
    $periode_id = (int)($_POST['periode_id'] ?? 0);
    
    if ($classe_id <= 0 || $periode_id <= 0) {
        die("Paramètres invalides");
    }
    
    try {
        $db = new PDO("mysql:host=localhost;dbname=isset_tsevie", 'root', '');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Récupérer les élèves
        $stmt = $db->prepare("SELECT id, nom, prenom, lieu_naissance, date_naissance FROM eleves WHERE classe_id = ? ORDER BY nom, prenom");
        $stmt->execute([$classe_id]);
        $eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($eleves)) {
            die("Aucun élève trouvé");
        }
        
        $generated_files = [];
        
        foreach ($eleves as $eleve) {
            // Récupérer les données pour le bulletin
            $stmt = $db->prepare("SELECT * FROM classes WHERE id = ?");
            $stmt->execute([$classe_id]);
            $classe = $stmt->fetch(PDO::FETCH_ASSOC);
            
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
            $stmt->execute([$eleve['id'], $classe_id, $semestre]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Préparer les données pour le template
            $bulletins = [];
            $moyenne_generale = 0;
            $total_coeff = 0;
            
            foreach ($notes as $note) {
                $moyenne = ($note['interro1'] + $note['interro2'] + $note['devoir'] + $note['compo']) / 4;
                $moyenne = round($moyenne, 2);
                
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
                $stmt_rang_matiere->execute([$note['matiere_id'], $classe_id, $semestre, $note['matiere_id'], $classe_id, $semestre, $moyenne]);
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
                    'moyenne' => $moyenne,
                    'professeur' => trim($note['professeur_prenom'] . ' ' . $note['professeur_nom']),
                    'rang_matiere' => $rang_matiere
                ];
                
                if ($moyenne > 0) {
                    $moyenne_generale += $moyenne * $note['coefficient'];
                    $total_coeff += $note['coefficient'];
                }
            }
            
            $moyenne_generale_precise = $total_coeff > 0 ? $moyenne_generale / $total_coeff : 0;
            $moyenne_generale = round($moyenne_generale_precise, 2);
            
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
                $stmt_rang->execute([$classe_id, $semestre, $eleve['id']]);
                $rang_data = $stmt_rang->fetch(PDO::FETCH_ASSOC);
                $rang = $rang_data ? $rang_data['rang'] : 1;
            }
            
            // Générer l'appréciation en fonction de la moyenne
            $appreciation = '';
            if ($moyenne_generale >= 16) {
                $appreciation = "Mention Très-Bien";
            } elseif ($moyenne_generale >= 14) {
                $appreciation = "Mention Bien";
            } elseif ($moyenne_generale >= 12) {
                $appreciation = "Mention Assez-Bien";
            } elseif ($moyenne_generale >= 10) {
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
            $moyenne_generale = $moyenne_generale;
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
            
            // Sauvegarder
            $filename = "Bulletin_" . preg_replace('/[^a-zA-Z0-9]/', '_', $eleve['nom'] . '_' . $eleve['prenom']) . ".pdf";
            $temp_file = sys_get_temp_dir() . '/' . uniqid('bulletin_', true) . '.pdf';
            
            $pdf->Output($temp_file, 'F');
            
            if (file_exists($temp_file) && filesize($temp_file) > 0) {
                $generated_files[] = [
                    'path' => $temp_file,
                    'name' => $filename
                ];
            }
        }
        
        if (empty($generated_files)) {
            die("Aucun PDF créé");
        }
        
        // Créer le ZIP
        $zip = new ZipArchive();
        $zip_name = 'Bulletins_Classe_' . $classe_id . '_' . date('Y-m-d_H-i-s') . '.zip';
        $zip_path = sys_get_temp_dir() . '/' . uniqid('bulletins_', true) . '.zip';
        
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            die("Impossible de créer le ZIP");
        }
        
        foreach ($generated_files as $file) {
            if (file_exists($file['path'])) {
                $zip->addFile($file['path'], $file['name']);
            }
        }
        
        $zip->close();
        
        if (!file_exists($zip_path)) {
            die("ZIP non créé");
        }
        
        // Nettoyer les buffers de sortie
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Envoyer le ZIP
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_name . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Transfer-Encoding: binary');
        
        readfile($zip_path);
        
        // Nettoyer après l'envoi
        unlink($zip_path);
        foreach ($generated_files as $file) {
            if (file_exists($file['path'])) {
                unlink($file['path']);
            }
        }
        
        exit;
        
    } catch (Exception $e) {
        die("Erreur: " . $e->getMessage());
    }
} else {
    die("Méthode non autorisée");
}
?>
