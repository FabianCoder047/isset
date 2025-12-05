<?php
// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Initialiser l'authentification
$auth = new Auth($db);

// Vérifier si l'utilisateur est connecté et est une secrétaire
if (!$auth->isLoggedIn() || !$auth->hasRole('secretaire')) {
    header('Location: /isset/login.php');
    exit();
}

// Récupérer les informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$user_name = $_SESSION['user_name'] ?? 'Secrétaire';

// Définir les URLs de base
$base_url = '/isset';
$secretaire_url = $base_url . '/secretaire';

// Récupérer la liste des classes pour le filtre
try {
    $query = "SELECT id, CONCAT(nom, ' ', niveau) as libelle FROM classes ORDER BY nom, niveau";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des classes: " . $e->getMessage();
    $classes = [];
}

// Récupérer les paramètres de filtre
$filtre_classe = $_GET['classe'] ?? '';
$filtre_sexe = $_GET['sexe'] ?? '';
$filtre_recherche = trim($_GET['recherche'] ?? '');

// Construire la requête avec les filtres
try {
    $query = "SELECT e.*, c.nom as classe_nom, c.niveau as classe_niveau 
              FROM eleves e 
              LEFT JOIN classes c ON e.classe_id = c.id 
              WHERE 1=1";
    
    $params = [];
    
    // Filtre par classe
    if (!empty($filtre_classe)) {
        $query .= " AND e.classe_id = ?";
        $params[] = $filtre_classe;
    }
    
    // Filtre par sexe
    if (!empty($filtre_sexe)) {
        $query .= " AND e.sexe = ?";
        $params[] = $filtre_sexe;
    }
    
    // Filtre par recherche (nom ou prénom)
    if (!empty($filtre_recherche)) {
        $query .= " AND (e.nom LIKE ? OR e.prenom LIKE ?)";
        $searchTerm = "%$filtre_recherche%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $query .= " ORDER BY e.nom, e.prenom";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des élèves: " . $e->getMessage();
    $eleves = [];
}

// Inclure le header
include __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-900">Liste des élèves</h1>
        <a href="<?php echo $secretaire_url; ?>/inscription.php" 
           class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            <i class="fas fa-plus mr-2"></i> Nouvelle inscription
        </a>
    </div>

    <!-- Formulaire de filtrage -->
    <div class="bg-white shadow rounded-lg p-4 mb-6">
        <form id="filtreForm" method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Champ de recherche par nom -->
            <div>
                <label for="recherche" class="block text-sm font-medium text-gray-700 mb-1">Recherche</label>
                <input type="text" name="recherche" id="recherche" 
                       value="<?php echo htmlspecialchars($filtre_recherche); ?>"
                       placeholder="Nom ou prénom" 
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>
            
            <!-- Filtre par classe -->
            <div>
                <label for="classe" class="block text-sm font-medium text-gray-700 mb-1">Classe</label>
                <select id="classe" name="classe" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="">Toutes les classes</option>
                    <?php foreach ($classes as $classe): ?>
                        <option value="<?php echo $classe['id']; ?>" <?php echo ($filtre_classe == $classe['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($classe['libelle']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Filtre par sexe -->
            <div>
                <label for="sexe" class="block text-sm font-medium text-gray-700 mb-1">Sexe</label>
                <select id="sexe" name="sexe" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="">Tous</option>
                    <option value="M" <?php echo ($filtre_sexe === 'M') ? 'selected' : ''; ?>>Masculin</option>
                    <option value="F" <?php echo ($filtre_sexe === 'F') ? 'selected' : ''; ?>>Féminin</option>
                </select>
            </div>
            
            <!-- Boutons d'action -->
            <div class="flex items-end space-x-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-filter mr-2"></i> Filtrer
                </button>
                <a href="?" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-redo mr-2"></i> Réinitialiser
                </a>
            </div>
        </form>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow overflow-hidden sm:rounded-lg">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Nom & Prénom
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Date de naissance
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Lieu de naissance
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Sexe
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Classe
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Téléphone
                        </th>
                        <th scope="col" class="relative px-6 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($eleves)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                Aucun élève enregistré pour le moment.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($eleves as $eleve): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-user text-blue-600"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($eleve['nom'] . ' ' . $eleve['prenom']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($eleve['sexe'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo !empty($eleve['date_naissance']) ? date('d/m/Y', strtotime($eleve['date_naissance'])) : 'Non spécifiée'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($eleve['lieu_naissance'] ?? 'Non spécifié'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($eleve['sexe'] ?? ''); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($eleve['classe_nom'] . ' ' . $eleve['classe_niveau'] ?? 'Non affecté'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo !empty($eleve['contact_parent']) ? htmlspecialchars($eleve['contact_parent']) : 'Non spécifié'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end space-x-2">
                                        <a href="modifier_eleve.php?id=<?php echo $eleve['id']; ?>" class="text-yellow-600 hover:text-yellow-900 mr-3" title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="supprimer_eleve.php?id=<?php echo $eleve['id']; ?>" class="text-red-600 hover:text-red-900" 
                                           onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet élève ?');" title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Inclure le footer
include __DIR__ . '/includes/footer.php';
?>

<script>
// Ajouter un gestionnaire d'événement pour la soumission du formulaire
// Cela permet de recharger la page avec les paramètres de filtre
// sans avoir besoin de JavaScript pour fonctionner
// (le formulaire fonctionnera même si JavaScript est désactivé)
document.addEventListener('DOMContentLoaded', function() {
    const filtreForm = document.getElementById('filtreForm');
    const selects = filtreForm.querySelectorAll('select');
    
    // Recharger la page lorsqu'une sélection change
    selects.forEach(select => {
        select.addEventListener('change', function() {
            filtreForm.submit();
        });
    });
    
    // Désactiver la soumission du formulaire avec la touche Entrée
    // pour éviter les soumissions accidentelles
    filtreForm.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            filtreForm.submit();
        }
    });
});
</script>
