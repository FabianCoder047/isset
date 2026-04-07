<?php
$pageTitle = 'Gestion des bulletins de notes';
include 'includes/header.php';

// Ajouter le script pour la gestion des exports PDF
echo '<script src="js/bulletin_pdf.js"></script>';

// Configuration des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 0); // Désactiver l'affichage des erreurs en production

// Initialiser les variables
$classes = [];
$periodes = [];
$error = null;

try {
    // Récupérer la liste des périodes d'évaluation depuis la base de données
    $query = "SELECT id, nom, date_debut, date_fin, annee_scolaire, est_actif 
              FROM periodes 
              ORDER BY date_debut ASC";
    $stmt = $db->query($query);
    if ($stmt) {
        $periodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des périodes: " . $e->getMessage());
    $error = "Erreur lors du chargement des périodes d'évaluation.";
}

try {
    // Récupérer la liste des classes
    $query = "SELECT * FROM classes ORDER BY niveau, nom";
    $stmt = $db->query($query);
    if ($stmt) {
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des classes: " . $e->getMessage());
    $error = "Erreur lors du chargement des classes. Veuillez réessayer.";
}

// Récupérer les paramètres de l'URL
$classe_id = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : 0;
$periode_id = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;

$eleves = [];
$matieres = [];
$bulletins = [];

// Configuration des erreurs (désactivé en production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Si une classe et une période sont sélectionnées
if ($classe_id > 0 && $periode_id > 0) {
    try {
        // Trouver la période sélectionnée
        $periode_courante = null;
        foreach ($periodes as $p) {
            if ($p['id'] == $periode_id) {
                $periode_courante = $p;
                break;
            }
        }

        // Déterminer le semestre en fonction du nom de la période
        $semestre = 1; // Par défaut, premier semestre
        if ($periode_courante && preg_match('/2|deuxi[eè]me|second/i', $periode_courante['nom'])) {
            $semestre = 2;
        }
        
        // Récupérer les élèves de la classe
        $query = "SELECT * FROM eleves WHERE classe_id = ? ORDER BY nom, prenom";
        $stmt = $db->prepare($query);
        $stmt->execute([$classe_id]);
        $eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer les matières de la classe
        $query = "SELECT * FROM matieres WHERE classe_id = ? ORDER BY nom";
        $stmt = $db->prepare($query);
        $stmt->execute([$classe_id]);
        $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($eleves)) {
            $error = "Aucun élève trouvé dans cette classe.";
        } elseif (empty($matieres)) {
            $error = "Aucune matière trouvée pour cette classe.";
        } else {
            $eleve_ids = array_column($eleves, 'id');
            $matiere_ids = array_column($matieres, 'id');
            
            // Déterminer le semestre en fonction du nom de la période
            $semestre = 1; // Par défaut, premier semestre
            if ($periode_courante && preg_match('/2|deuxi[eè]me|second/i', $periode_courante['nom'])) {
                $semestre = 2;
            }
            
            // Requête plus simple pour tester
            $query = "SELECT n.*, m.nom as matiere_nom, m.coefficient 
                     FROM notes n
                     JOIN matieres m ON n.matiere_id = m.id
                     WHERE n.classe_id = ? AND n.semestre = ?";
            $params = [$classe_id, $semestre];
            
            try {
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new Exception("Erreur de préparation de la requête");
                }
                
                $result = $stmt->execute($params);
                if ($result === false) {
                    throw new Exception("Erreur lors de l'exécution de la requête");
                }
                
                $notes_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $bulletins = []; // Initialiser le tableau des bulletins
                
                // Si on a des résultats, les traiter
                if (!empty($notes_result) && !empty($eleves) && !empty($matieres)) {
                    foreach ($eleves as $eleve) {
                        $eleve_id = $eleve['id'];
                        $bulletins[$eleve_id] = [
                            'eleve_id' => $eleve_id,
                            'eleve_nom' => $eleve['nom'] . ' ' . $eleve['prenom'],
                            'matieres' => []
                        ];
                        
                        // Initialiser les notes pour chaque matière
                        foreach ($matieres as $matiere) {
                            $note_data = [
                                'matiere_id' => $matiere['id'],
                                'matiere_nom' => $matiere['nom'],
                                'coefficient' => $matiere['coefficient'] ?? 1,
                                'interro1' => null,
                                'interro2' => null,
                                'devoir' => null,
                                'compo' => null,
                                'moyenne' => null
                            ];
                            
                            // Chercher les notes pour cet élève et cette matière
                            foreach ($notes_result as $note) {
                                if (isset($note['eleve_id'], $note['matiere_id']) && 
                                    $note['eleve_id'] == $eleve_id && 
                                    $note['matiere_id'] == $matiere['id']) {
                                    
                                    $note_data['interro1'] = $note['interro1'] ?? null;
                                    $note_data['interro2'] = $note['interro2'] ?? null;
                                    $note_data['devoir'] = $note['devoir'] ?? null;
                                    $note_data['compo'] = $note['compo'] ?? null;
                                    
                                    // Calculer la moyenne avec la bonne formule: (I1 + I2 + Devoir + Compo) / 4
                                    $notes = [];
                                    if (!empty($note['interro1'])) {
                                        $notes[] = (float)$note['interro1'];
                                    }
                                    if (!empty($note['interro2'])) {
                                        $notes[] = (float)$note['interro2'];
                                    }
                                    if (!empty($note['devoir'])) {
                                        $notes[] = (float)$note['devoir'];
                                    }
                                    if (!empty($note['compo'])) {
                                        $notes[] = (float)$note['compo'];
                                    }
                                    
                                    if (count($notes) >= 4) {
                                        $moyenne = array_sum($notes) / 4;
                                        $note_data['moyenne'] = number_format($moyenne, 2, ',', ' ');
                                    } else {
                                        $note_data['moyenne'] = null; // Pas assez de notes pour calculer
                                    }
                                    
                                    break;
                                }
                            }
                            
                            $bulletins[$eleve_id]['matieres'][$matiere['id']] = $note_data;
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Erreur lors de la récupération des notes: " . $e->getMessage());
                $error = "Une erreur est survenue lors du chargement des notes: " . $e->getMessage();
            }
        }
        
    } catch (PDOException $e) {
        error_log("Erreur: " . $e->getMessage());
        $error = "Erreur lors du chargement des données: " . $e->getMessage();
    }
}

// Afficher les messages d'erreur s'il y en a
if (!empty($error)) {
    echo '<div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">';
    echo '    <div class="flex">';
    echo '        <div class="flex-shrink-0">';
    echo '            <i class="fas fa-exclamation-circle text-red-400"></i>';
    echo '        </div>';
    echo '        <div class="ml-3">';
    echo '            <p class="text-sm text-red-700">';
    echo '                ' . htmlspecialchars($error);
    echo '            </p>';
    echo '        </div>';
    echo '    </div>';
    echo '</div>';
}

echo '<div class="bg-white p-6 rounded-lg shadow mb-8">';
echo '    <div class="flex justify-between items-center mb-6">';
echo '        <h2 class="text-xl font-semibold">Générer les bulletins de notes</h2>';
echo '    </div>';
echo '    ';
echo '    <form method="GET" class="space-y-4">';
echo '        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">';
echo '            <div>';
echo '                <label for="classe_id" class="block text-sm font-medium text-gray-700">Classe</label>';
echo '                <select id="classe_id" name="classe_id" required';
echo '                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">';
echo '                    <option value="">Sélectionner une classe</option>';

foreach ($classes as $classe) {
    $selected = ($classe_id == $classe['id']) ? 'selected' : '';
    echo '                    <option value="' . $classe['id'] . '" ' . $selected . '>';
    echo htmlspecialchars($classe['nom'] . ' ' . $classe['niveau'], ENT_QUOTES, 'UTF-8');
    echo '</option>';
}

echo '                </select>';
echo '            </div>';
echo '            ';
echo '            <div>';
echo '                <label for="periode_id" class="block text-sm font-medium text-gray-700">Période d\'évaluation</label>';
echo '                <select id="periode_id" name="periode_id" required';
echo '                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">';
echo '                    <option value="">Sélectionner une période</option>';

foreach ($periodes as $periode) {
    $selected = ($periode_id == $periode['id']) ? 'selected' : '';
    echo '                    <option value="' . $periode['id'] . '" ' . $selected . '>';
    echo htmlspecialchars($periode['nom'], ENT_QUOTES, 'UTF-8');
    echo '</option>';
}

echo '                </select>';
echo '            </div>';
echo '            ';
echo '            <div class="flex items-end">';
echo '                <button type="submit"';
echo '                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">';
echo '                    <i class="fas fa-search mr-2"></i> Afficher';
echo '                </button>';

if ($classe_id > 0 && $periode_id > 0 && !empty($eleves)) {
    // Récupérer le nom de la classe pour le nom du fichier
    $classe_nom = '';
    $classe_nom_query = $db->prepare("SELECT CONCAT(niveau, '_', nom) as nom_classe FROM classes WHERE id = ?");
    if ($classe_nom_query->execute([$classe_id])) {
        $classe = $classe_nom_query->fetch(PDO::FETCH_ASSOC);
        $classe_nom = $classe ? $classe['nom_classe'] : 'Classe';
    }
    
    // Nettoyer le nom de la classe pour éviter les problèmes
    $classe_nom = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $classe_nom);
    $classe_nom = trim($classe_nom, '_');
    
    // Debug pour voir le nom de la classe
    error_log("Classe ID: $classe_id, Classe nom nettoyé: '$classe_nom'");
    
    echo '                <div class="flex space-x-2 ml-2">';
    echo '                    <button id="export-classe-pdf" ';
    echo '                            data-classe="' . $classe_id . '" ';
    echo '                            data-periode="' . $periode_id . '" ';
    echo '                            data-classe-nom="' . htmlspecialchars($classe_nom, ENT_QUOTES, 'UTF-8') . '" ';
    echo '                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">';
    echo '                        <i class="fas fa-file-pdf mr-2"></i> Exporter les bulletins';
    echo '                    </button>';
    echo '                </div>';
}

echo '            </div>';
echo '        </div>';
echo '    </form>';
echo '</div>';

// Afficher les bulletins si une classe et une période sont sélectionnées
if ($classe_id > 0 && $periode_id > 0) { 
    // Afficher une alerte s'il y a une erreur
    if (!empty($error)) { 
        echo '<div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">';
        echo '    <div class="flex">';
        echo '        <div class="flex-shrink-0">';
        echo '            <i class="fas fa-exclamation-triangle h-5 w-5 text-yellow-400"></i>';
        echo '        </div>';
        echo '        <div class="ml-3">';
        echo '            <p class="text-sm text-yellow-700">';
        echo '                ' . htmlspecialchars($error);
        echo '            </p>';
        echo '        </div>';
        echo '    </div>';
        echo '</div>';
    } else { // Pas d'erreur, afficher les bulletins
        $classe = array_filter($classes, function($c) use ($classe_id) {
            return $c['id'] == $classe_id;
        });
        $classe = reset($classe);
        
        $periode = array_filter($periodes, function($p) use ($periode_id) {
            return $p['id'] == $periode_id;
        });
        $periode = reset($periode);
        
        echo '<div class="bg-white shadow overflow-hidden sm:rounded-lg mb-8">';
        echo '    <div class="px-4 py-5 sm:px-6 bg-gray-50">';
        echo '        <h3 class="text-lg font-medium leading-6 text-gray-900">';
        echo '            Bulletins de notes - ';
        echo '            ' . htmlspecialchars($classe['niveau'] . ' ' . $classe['nom'] . ' - ' . $periode['nom'] . ' ' . $periode['annee_scolaire'], ENT_QUOTES, 'UTF-8');
        echo '        </h3>';
        echo '        <p class="mt-1 max-w-2xl text-sm text-gray-500">';
        echo '            Liste des élèves et de leurs moyennes pour la période sélectionnée.';
        echo '        </p>';
        echo '    </div>';
        echo '    <div class="px-4 py-5 sm:p-0">';
        echo '        <table class="min-w-full divide-y divide-gray-200">';
        echo '            <thead class="bg-gray-50">';
        echo '                <tr>';
        echo '                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">';
        echo '                        Élève';
        echo '                    </th>';
        echo '                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">';
        echo '                        Moyenne';
        echo '                    </th>';
        echo '                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">';
        echo '                        Actions';
        echo '                    </th>';
        echo '                </tr>';
        echo '            </thead>';
        echo '            <tbody class="bg-white divide-y divide-gray-200">';
        
        // Vérifier si $eleves est défini et n'est pas vide
        if (!empty($eleves)) { 
            foreach ($eleves as $eleve) { 
                $has_notes = false;
                $moyenne_eleve = 0;
                $total_coeff = 0;
                
                if (isset($bulletins[$eleve['id']])) {
                    foreach ($bulletins[$eleve['id']]['matieres'] as $matiere_id => $matiere_data) {
                        if ($matiere_data['moyenne'] !== null) {
                            $has_notes = true;
                            $moyenne_eleve += (float)str_replace(',', '.', $matiere_data['moyenne']) * $matiere_data['coefficient'];
                            $total_coeff += $matiere_data['coefficient'];
                        }
                    }
                }
                
                if ($has_notes && $total_coeff > 0) {
                    $moyenne_generale = $moyenne_eleve / $total_coeff;
                    $couleur = $moyenne_generale >= 10 ? 'text-green-600' : 'text-red-600';
                } else {
                    $couleur = 'text-gray-400';
                }
                
                echo '                <tr>';
                echo '                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">';
                echo '                        ' . htmlspecialchars($eleve['nom'] . ' ' . $eleve['prenom'], ENT_QUOTES, 'UTF-8');
                echo '                    </td>';
                echo '                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">';
                
                if ($has_notes && $total_coeff > 0) {
                    $moyenne_generale = $moyenne_eleve / $total_coeff;
                    $couleur = $moyenne_generale >= 10 ? 'text-green-600' : 'text-red-600';
                    echo '                        <span class="font-semibold ' . $couleur . '">' . number_format($moyenne_generale, 2, ',', ' ') . ' / 20</span>';
                } else {
                    echo '                        <span class="text-gray-400">-</span>';
                }
                
                echo '                    </td>';
                echo '                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">';
                
                if ($has_notes) {
                    echo '                        <a href="generer_bulletin_simple.php?eleve_id=' . $eleve['id'] . '&classe_id=' . $classe_id . '&periode_id=' . $periode_id . '" ';
                    echo '                           class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"';
                    echo '                           target="_blank"';
                    echo '                           title="Télécharger le bulletin">';
                    echo '                            <i class="fas fa-file-pdf mr-1"></i> Télécharger';
                    echo '                        </a>';
                } else {
                    echo '                        <span class="text-gray-400 text-sm">-</span>';
                }
                
                echo '                    </td>';
                echo '                </tr>';
            }
        } else { // Aucun élève trouvé
            echo '                <tr>';
            echo '                    <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">';
            echo '                        Aucun élève trouvé dans cette classe.';
            echo '                    </td>';
            echo '                </tr>';
        }
        
        echo '            </tbody>';
        echo '        </table>';
        echo '    </div>';
        echo '</div>';
    }
} else { // Aucune sélection de classe ou période 
    echo '<div class="bg-white p-6 rounded-lg shadow">';
    echo '    <div class="text-center">';
    echo '        <i class="fas fa-clipboard-list text-4xl text-gray-400 mb-4"></i>';
    echo '        <div class="bg-white p-6 rounded-lg shadow mb-8">';
    echo '            <div class="text-center">';
    echo '                <h3 class="text-lg font-medium text-gray-900 mb-2">Aucune sélection</h3>';
    echo '                <p class="text-gray-500">Veuillez sélectionner une classe et une période pour afficher les bulletins de notes.</p>';
    echo '            </div>';
    echo '        </div>';
    echo '    </div>';
    echo '</div>';
}

?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Mettre à jour l'URL avec les paramètres de filtre
    const form = document.querySelector("form");
    const classeSelect = document.getElementById("classe_id");
    const periodeSelect = document.getElementById("periode_id");
    
    [classeSelect, periodeSelect].forEach(select => {
        select.addEventListener("change", function() {
            // Si les deux champs sont remplis, soumettre le formulaire
            if (classeSelect.value && periodeSelect.value) {
                form.submit();
            }
        });
    });
    
    // Gestion de l'impression
    const printBtn = document.getElementById("printBtn");
    if (printBtn) {
        printBtn.addEventListener("click", function() {
            window.print();
        });
    }
});
</script>
<?php

include 'includes/footer.php';
