<?php
// Charger l'autoloader de Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Activer l'affichage des erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Activer la journalisation des erreurs
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/pdf_errors.log');

// En-têtes pour le débogage
header('Content-Type: text/html; charset=utf-8');

// Journaliser le début de l'exécution
error_log("=== Début de la génération du PDF ===");

// Vérifier les paramètres
$eleve_id = isset($_GET['eleve_id']) ? (int)$_GET['eleve_id'] : 0;
$classe_id = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : 0;
$periode_id = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
$export_type = $_GET['export'] ?? ''; // 'classe' ou 'selection'
$eleve_ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];

// Vérification des paramètres obligatoires
if (empty($export_type) && ($eleve_id <= 0 || $classe_id <= 0 || $periode_id <= 0)) {
    $_SESSION['error'] = "Paramètres manquants ou invalides.";
    header('Location: bulletins.php');
    exit;
}

// Si c'est une exportation de sélection mais qu'aucun élève n'est sélectionné
if ($export_type === 'selection' && empty($eleve_ids)) {
    $_SESSION['error'] = "Aucun élève sélectionné pour l'exportation.";
    header('Location: bulletins.php');
    exit;
}

// Charger les dépendances
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Journaliser la connexion à la base de données réussie
error_log("Connexion à la base de données établie avec succès");

/**
 * Génère les données du bulletin pour un élève
 */
function genererBulletinEleve($db, $eleve_id, $classe_id, $periode_id) {
    // Vérification des paramètres
    if (empty($eleve_id) || empty($classe_id) || empty($periode_id)) {
        throw new Exception("Paramètres manquants pour la génération du bulletin.");
    }

    // Récupérer les informations de l'élève
    $stmt = $db->prepare("SELECT * FROM eleves WHERE id = ? AND classe_id = ?");
    if (!$stmt->execute([$eleve_id, $classe_id])) {
        throw new Exception("Erreur lors de la récupération des informations de l'élève.");
    }
    $eleve = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$eleve) {
        throw new Exception("Élève non trouvé dans cette classe.");
    }

    // Récupérer les informations de la classe
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ?");
    if (!$stmt->execute([$classe_id])) {
        throw new Exception("Erreur lors de la récupération des informations de la classe.");
    }
    $classe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$classe) {
        throw new Exception("Classe non trouvée.");
    }

    // Récupérer les informations de la période
    $stmt = $db->prepare("SELECT * FROM periodes WHERE id = ?");
    if (!$stmt->execute([$periode_id])) {
        throw new Exception("Erreur lors de la récupération des informations de la période.");
    }
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$periode) {
        throw new Exception("Période non trouvée.");
    }

    // Initialiser les informations de l'école
    $ecole = [
        'nom' => 'ISSET Tsévié',
        'ville' => 'Tsévié',
        'pays' => 'Togo'
    ];

    // Déterminer le semestre
    $semestre = 1;
    if (preg_match('/2|deuxi[eè]me|second/i', $periode['nom'])) {
        $semestre = 2;
    }
    
    // Récupérer les notes de l'élève pour la période
    $query = "
        SELECT 
            n.*, 
            m.nom as matiere_nom,
            m.coefficient,
            (n.interro1 + n.interro2 + n.devoir + (n.compo * 2)) / 5 as moyenne,
            '' as appreciation,  -- Appréciation gérée logiquement
            COALESCE(u.nom, '') as professeur_nom,
            COALESCE(u.prenom, '') as professeur_prenom
        FROM notes n
        JOIN matieres m ON n.matiere_id = m.id
        LEFT JOIN enseignements e ON e.matiere_id = m.id
        LEFT JOIN utilisateurs u ON e.professeur_id = u.id AND u.role = 'professeur'
        WHERE n.eleve_id = ? 
        AND n.classe_id = ?
        AND n.semestre = ?
        ORDER BY m.nom
    ";

    $stmt = $db->prepare($query);
    if (!$stmt->execute([$eleve_id, $classe_id, $semestre])) {
        throw new Exception("Erreur lors de la récupération des notes de l'élève.");
    }
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Préparer les données pour le template
    $bulletins = [];
    $moyenne_generale = 0;
    $total_coeff = 0;
    $has_notes = false;

    foreach ($notes as $note) {
        $moyenne = ($note['interro1'] + $note['interro2'] + $note['devoir'] + ($note['compo'] * 2)) / 5;
        $moyenne = round($moyenne, 2);
        
        $bulletins[$note['matiere_id']] = [
            'matiere_nom' => $note['matiere_nom'],
            'coefficient' => $note['coefficient'],
            'interro1' => $note['interro1'],
            'interro2' => $note['interro2'],
            'devoir' => $note['devoir'],
            'compo' => $note['compo'],
            'moyenne' => $moyenne,
            'appreciation' => $note['appreciation'] ?? '',
            'professeur' => trim(($note['professeur_nom'] ?? ''))
        ];

        if ($moyenne > 0) {
            $moyenne_generale += $moyenne * $note['coefficient'];
            $total_coeff += $note['coefficient'];
            $has_notes = true;
        }
    }

    // Calculer la moyenne générale
    $moyenne_generale = $has_notes ? $moyenne_generale / $total_coeff : 0;
    $moyenne_generale = round($moyenne_generale, 2);

    // Déterminer le rang de l'élève dans la classe (si nécessaire)
    $rang = 'Non classé';
    if ($has_notes) {
        $query = "
            SELECT COUNT(*) + 1 as rang
            FROM (
                SELECT e.id, AVG((n.interro1 + n.interro2 + n.devoir + (n.compo * 2)) / 5) as moyenne
                FROM eleves e
                JOIN notes n ON e.id = n.eleve_id
                WHERE n.classe_id = ? AND n.semestre = ?
                GROUP BY e.id
                HAVING AVG((n.interro1 + n.interro2 + n.devoir + (n.compo * 2)) / 5) > ?
            ) as classement
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$classe_id, $semestre, $moyenne_generale]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $rang = $result ? $result['rang'] : '1er';
    }

    // Appréciation générale générée dynamiquement en fonction de la moyenne
    $appreciation = '';
    if ($moyenne_generale >= 16) {
        $appreciation = "Excellente année scolaire avec une très bonne maîtrise des compétences. Félicitations !";
    } elseif ($moyenne_generale >= 14) {
        $appreciation = "Très bon travail tout au long de la période. Continue ainsi !";
    } elseif ($moyenne_generale >= 12) {
        $appreciation = "Bon travail, quelques efforts supplémentaires seraient bénéfiques.";
    } elseif ($moyenne_generale >= 10) {
        $appreciation = "Résultats satisfaisants, mais des progrès sont nécessaires.";
    } else {
        $appreciation = "Des efforts importants sont nécessaires pour progresser. Travaillez davantage.";
    }

    // Récupérer l'effectif de la classe
    $stmt = $db->prepare("SELECT COUNT(*) as effectif FROM eleves WHERE classe_id = ?");
    $stmt->execute([$classe_id]);
    $effectif = $stmt->fetch(PDO::FETCH_ASSOC)['effectif'];

    // Retourner les données formatées pour le template
    return [
        'eleve' => $eleve,
        'classe' => $classe,
        'periode' => $periode,
        'ecole' => $ecole,
        'bulletins' => $bulletins,
        'moyenne_generale' => $moyenne_generale,
        'rang' => $rang,
        'appreciation' => $appreciation,
        'effectif' => $effectif,
        'semestre' => $semestre
    ];
}

/**
 * Génère le contenu HTML d'un bulletin à partir des données fournies
 */
function genererHtmlBulletin($data) {
    extract($data);
    ob_start();
    include __DIR__ . '/templates/bulletin_template.php';
    return ob_get_clean();
}

try {
    // Vérifier si TCPDF est disponible
    if (!class_exists('TCPDF')) {
        throw new Exception("La bibliothèque TCPDF n'est pas installée.");
    }

    // Initialiser TCPDF
    require_once(dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/tcpdf.php');
    
    // Créer une classe personnalisée qui étend TCPDF pour ajouter le filigrane
    class PDF_With_Watermark extends TCPDF {
        // Page header
        public function Header() {
            // Ajouter le filigrane dans l'en-tête de chaque page
            $this->AddWatermark();
        }
        
        // Page footer
        public function Footer() {
            // Rien dans le footer
        }
        
        // Ajouter le filigrane
        public function AddWatermark() {
            // Sauvegarder l'état graphique
            $this->SetAlpha(0.1);
            $this->SetFont('helvetica', 'B', 50);
            $this->SetTextColor(150, 150, 150);
            
            // Obtenir les dimensions de la page
            $pageWidth = $this->getPageWidth();
            $pageHeight = $this->getPageHeight();
            
            // Positionner le filigrane au centre de la page
            $this->StartTransform();
            $this->Rotate(45, $pageWidth/2, $pageHeight/2);
            
            // Ajouter le texte du filigrane
            $this->Text(30, $pageHeight/2 - 50, 'ISSET-TSEVIE');
            $this->Text(30, $pageHeight/2 + 50, 'ISSET-TSEVIE');
            
            $this->StopTransform();
            
            // Restaurer l'état graphique
            $this->SetAlpha(1);
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('helvetica', '', 10);
        }
    }
    
    // Créer une instance de notre classe personnalisée
    $pdf = new PDF_With_Watermark('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Configuration du document
    $pdf->SetCreator('IST-ISSIG');
    $pdf->SetAuthor('IST-ISSIG');
    $pdf->SetTitle('Bulletin de notes');
    $pdf->SetSubject('Bulletin de notes');
    $pdf->SetKeywords('Bulletin, Notes, Évaluation');
    
    // Désactiver l'en-tête et le pied de page par défaut
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Définir les marges (gauche, haut, droite)
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    
    // Activer le saut de page automatique
    $pdf->SetAutoPageBreak(true, 25);
    
    // Définir le facteur d'échelle pour les images
    $pdf->setImageScale(1.25);
    
    // Police par défaut
    $pdf->SetFont('helvetica', '', 10);
    
    // Activer les sous-ensemble de polices
    $pdf->setFontSubsetting(true);
    
    // Déterminer si on exporte un seul élève ou plusieurs
    if (!empty($export_type)) {
        // Exporter plusieurs bulletins (classe entière ou sélection)
        if ($export_type === 'classe') {
            // Récupérer tous les élèves de la classe avec leurs noms pour le nom de fichier
            $stmt = $db->prepare("SELECT id, CONCAT(nom, '_', prenom) as nom_fichier FROM eleves WHERE classe_id = ? ORDER BY nom, prenom");
            if (!$stmt->execute([$classe_id])) {
                throw new Exception("Erreur lors de la récupération des élèves de la classe.");
            }
            $eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($eleves)) {
                throw new Exception("Aucun élève trouvé dans cette classe.");
            }
            
            // Créer un tableau d'IDs pour la compatibilité avec le code existant
            $eleve_ids = array_column($eleves, 'id');
            
        } elseif ($export_type === 'selection' && empty($eleve_ids)) {
            throw new Exception("Aucun élève sélectionné pour l'exportation.");
        }
        
        // Générer un bulletin pour chaque élève
        $count = 0;
        $generated_files = [];
        
        // Si on a des noms d'élèves, on les utilise pour le nom de fichier
        $has_eleve_names = isset($eleves) && !empty($eleves);
        
        foreach ($eleve_ids as $index => $current_eleve_id) {
            try {
                $data = genererBulletinEleve($db, $current_eleve_id, $classe_id, $periode_id);
                $html = genererHtmlBulletin($data);
                
                // Créer un nouveau PDF pour chaque élève
                $student_pdf = new PDF_With_Watermark('P', 'mm', 'A4', true, 'UTF-8', false);
                
                // Configuration du document pour ce PDF
                $student_pdf->SetCreator('ISSET');
                $student_pdf->SetAuthor('ISSET');
                $student_pdf->SetTitle('Bulletin de notes');
                
                // Ajouter la première page
                $student_pdf->AddPage();
                
                // Ajouter des styles CSS spécifiques pour le PDF
                $css = '
                <style>
                    body { font-family: helvetica; font-size: 10pt; }
                    .table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                    .table th, .table td { border: 1px solid #000; padding: 4px; text-align: center; }
                    .table th { background-color: #f0f0f0; font-weight: bold; }
                    .text-center { text-align: center; }
                    .text-right { text-align: right; }
                    .text-bold { font-weight: bold; }
                    .signature-line { border-top: 1px solid #000; width: 150px; margin: 30px auto 0; padding-top: 5px; }
                    .header-logo { max-width: 80px; height: auto; }
                    .header-sceau { max-width: 70px; height: auto; }
                </style>';
                
                // Écrire le contenu HTML avec les styles
                $student_pdf->writeHTML($css . $html, true, false, true, false, '');
                
                // Générer un nom de fichier unique pour cet élève
                $filename = '';
                if ($has_eleve_names) {
                    $eleve_nom = $eleves[$index]['nom_fichier'];
                    $filename = 'Bulletin_' . $eleve_nom . '_' . date('Y-m-d') . '.pdf';
                } else {
                    $filename = 'Bulletin_eleve_' . $current_eleve_id . '_' . date('Y-m-d') . '.pdf';
                }
                
                // Nettoyer le nom du fichier
                $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
                
                // Sauvegarder le PDF dans un fichier temporaire
                $temp_file = sys_get_temp_dir() . '/' . uniqid('bulletin_', true) . '.pdf';
                $student_pdf->Output($temp_file, 'F');
                
                // Ajouter le fichier à la liste des fichiers générés
                $generated_files[] = [
                    'path' => $temp_file,
                    'name' => $filename
                ];
                
                $count++;
                
            } catch (Exception $e) {
                // Enregistrer l'erreur mais continuer avec les autres élèves
                error_log("Erreur lors de la génération du bulletin pour l'élève $current_eleve_id: " . $e->getMessage());
                continue;
            }
        }
        
        if ($count === 0) {
            throw new Exception("Aucun bulletin n'a pu être généré.");
        }
        
        // Si on a généré un seul fichier, le renvoyer directement
        if (count($generated_files) === 1) {
            $file = $generated_files[0];
            if (file_exists($file['path'])) {
                // Envoyer le fichier PDF unique
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
                header('Content-Length: ' . filesize($file['path']));
                readfile($file['path']);
                unlink($file['path']);
                exit;
            } else {
                throw new Exception("Le fichier PDF n'a pas pu être trouvé : " . $file['path']);
            }
        } 
        // Si on a plusieurs fichiers, créer un ZIP
        else if (count($generated_files) > 1) {
            // Vérifier que l'extension ZIP est disponible
            if (!class_exists('ZipArchive')) {
                throw new Exception("L'extension PHP Zip n'est pas installée ou activée sur le serveur.");
            }
            
            $zip = new ZipArchive();
            $zip_name = 'Bulletins_Classe_' . $classe_id . '_' . date('Y-m-d') . '.zip';
            $zip_path = sys_get_temp_dir() . '/' . uniqid('bulletins_', true) . '.zip';
            
            // Vérifier que le répertoire temporaire est accessible en écriture
            if (!is_writable(sys_get_temp_dir())) {
                throw new Exception("Le répertoire temporaire n'est pas accessible en écriture : " . sys_get_temp_dir());
            }
            
            // Vérifier que tous les fichiers PDF existent avant de créer le ZIP
            foreach ($generated_files as $file) {
                if (!file_exists($file['path'])) {
                    throw new Exception("Le fichier PDF n'a pas pu être généré : " . $file['path']);
                }
            }
            
            // Créer le fichier ZIP
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Impossible de créer le fichier ZIP à l'emplacement : " . $zip_path);
            }
            
            // Ajouter chaque fichier au ZIP
            foreach ($generated_files as $file) {
                if (file_exists($file['path'])) {
                    if (!$zip->addFile($file['path'], $file['name'])) {
                        $zip->close();
                        throw new Exception("Impossible d'ajouter le fichier au ZIP : " . $file['path']);
                    }
                }
            }
            
            // Fermer le ZIP
            if (!$zip->close()) {
                throw new Exception("Erreur lors de la fermeture de l'archive ZIP");
            }
            
            // Vérifier que le fichier ZIP a été créé
            if (!file_exists($zip_path)) {
                throw new Exception("L'archive ZIP n'a pas pu être créée");
            }
            
            // Vider tous les tampons de sortie
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Désactiver la compression pour le téléchargement
            if (ini_get('zlib.output_compression')) {
                ini_set('zlib.output_compression', 'Off');
            }
            
            // Envoyer les en-têtes HTTP
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Cache-Control: private', false);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_name . '"');
            header('Content-Length: ' . filesize($zip_path));
            header('Content-Transfer-Encoding: binary');
            
            // Lire et envoyer le fichier par morceaux
            $chunk_size = 1024 * 1024; // 1MB par morceau
            $handle = fopen($zip_path, 'rb');
            if ($handle === false) {
                throw new Exception("Impossible d'ouvrir le fichier ZIP pour lecture");
            }
            
            while (!feof($handle)) {
                echo fread($handle, $chunk_size);
                ob_flush();
                flush();
            }
            
            fclose($handle);
            
            // Supprimer les fichiers temporaires
            @unlink($zip_path);
            foreach ($generated_files as $file) {
                if (file_exists($file['path'])) {
                    @unlink($file['path']);
                }
            }
            
            exit;
        }
    } // Fin du if (!empty($export_type))
    
    // Si on arrive ici, c'est qu'on a un seul élève à exporter
    $data = genererBulletinEleve($db, $eleve_id, $classe_id, $periode_id);
    $html = genererHtmlBulletin($data);
    
    // Générer un nom de fichier approprié avec le nom de l'élève
    if (!empty($export_type) && $export_type === 'classe') {
        // Pour l'export de la classe, utiliser un nom générique
        $classe_nom = '';
        $stmt = $db->prepare("SELECT CONCAT(niveau, ' ', nom) as nom_classe FROM classes WHERE id = ?");
        if ($stmt->execute([$classe_id])) {
            $classe = $stmt->fetch(PDO::FETCH_ASSOC);
            $classe_nom = $classe ? $classe['nom_classe'] : 'classe_' . $classe_id;
        }
        $filename = 'Bulletins_' . str_replace(' ', '_', $classe_nom) . '_' . date('Y-m-d') . '.pdf';
    } else {
        // Pour un seul élève, inclure son nom dans le fichier
        $eleve_nom = '';
        $stmt = $db->prepare("SELECT CONCAT(nom, ' ', prenom) as nom_complet FROM eleves WHERE id = ?");
        if ($stmt->execute([$eleve_id])) {
            $eleve = $stmt->fetch(PDO::FETCH_ASSOC);
            $eleve_nom = $eleve ? $eleve['nom_complet'] : 'eleve_' . $eleve_id;
        }
        $filename = 'Bulletin_' . str_replace(' ', '_', $eleve_nom) . '_' . date('Y-m-d') . '.pdf';
    }
    
    // Utiliser le nom de fichier fourni ou en générer un
    $filename = isset($_POST['filename']) ? $_POST['filename'] : 'bulletins.pdf';
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    
    // Désactiver la mise en cache
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Ajouter le contenu au PDF
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Envoyer le PDF au navigateur
    $pdf->Output($filename, 'D');
    exit;

} catch (Exception $e) {
    // Journaliser l'erreur
    $errorMessage = "Erreur lors de la génération du PDF : " . $e->getMessage() . "\n" . $e->getTraceAsString();
    error_log($errorMessage);
    
    // Si c'est une requête AJAX, renvoyer une réponse JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $errorMessage]);
    } else {
        // Afficher l'erreur directement pour le débogage
        echo "<h1>Erreur lors de la génération du PDF</h1>";
        echo "<pre>" . htmlspecialchars($errorMessage) . "</pre>";
        echo "<p>Veuillez vérifier les logs pour plus de détails.</p>";
        
        // Si la session est disponible, enregistrer également l'erreur
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['error'] = $errorMessage;
        }
    }
    exit;
}

// Journaliser la fin de l'exécution
error_log("=== Fin de la génération du PDF ===\n");
