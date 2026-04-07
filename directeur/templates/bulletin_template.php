<?php
/**
 * Template bulletin corrigé :
 * Bandeau bleu plus fin, logo à gauche, sceau à droite, texte centré.
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            margin: 0;
        }

        /* --- HEADER --- */
        .header {
            background-color: #003399;
            color: white;
            text-align: center;
            padding: 12px 20px 18px; /* Hauteur réduite */
            position: relative;
        }

        /* Logo gauche et sceau droite */
        .header .logo-left {
            position: absolute;
            left: 20px;
            top: 10px;
            height: 80px;
            float: left;
        }

        .header .logo-right {
            position: absolute;
            right: 20px;
            top: 10px;
            height: 80px;
            float: right;
        }

        .header h2 {
            margin: 0;
            font-size: 13pt;
            font-weight: bold;
        }
        .header p {
            margin: 2px 0;
            font-size: 10pt;
        }
        .header h3 {
            margin: 5px 0 0;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        /* --- Infos Élève --- */
        .info-eleve {
            width: 100%;
            font-size: 10pt;
            text-align : center;
        }
        .info-eleve td {
            padding: 3px 6px;
        }
        .info-eleve strong {
            color: #003399;
        }

        /* --- Tableau Notes --- */
        table.notes {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            table-layout: fixed;
        }
        table.notes th, table.notes td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
        }
        table.notes th {
            background-color: #e9e9e9;
        }
        /* Colonnes matières et professeurs plus larges */
        table.notes th:nth-child(1), table.notes td:nth-child(1) {
            width: 30%;
            text-align: left;
            padding-left: 8px;
            white-space: nowrap;
            overflow: visible;
        }
        table.notes th:nth-child(10), table.notes td:nth-child(10) {
            width: 25%;
            text-align: left;
            padding-left: 8px;
            white-space: nowrap;
            overflow: visible;
        }
        /* Colonnes numériques plus étroites */
        table.notes th:nth-child(2), table.notes td:nth-child(2),
        table.notes th:nth-child(3), table.notes td:nth-child(3),
        table.notes th:nth-child(4), table.notes td:nth-child(4),
        table.notes th:nth-child(5), table.notes td:nth-child(5),
        table.notes th:nth-child(6), table.notes td:nth-child(6),
        table.notes th:nth-child(7), table.notes td:nth-child(7),
        table.notes th:nth-child(8), table.notes td:nth-child(8),
        table.notes th:nth-child(9), table.notes td:nth-child(9) {
            width: 6%;
            min-width: 50px;
        }

        /* --- Résultats --- */
        .resultats {
            margin-top: 15px;
            width: 100%;
            border-collapse: collapse;
        }
        .resultats td {
            border: 1px solid #000;
            padding: 5px;
            font-size: 10pt;
        }

        /* --- Appréciation --- */
        .appreciation {
            margin-top: 15px;
            border: 1px solid #000;
            padding: 8px;
            background-color: #f9f9f9;
        }

        /* --- Signatures --- */
        .signatures {
            margin-top: 30px;
            width: 100%;
            font-size: 10pt;
        }
        .signatures td {
            text-align: center;
            padding-top: 40px;
        }

        /* --- Pied de page --- */
        .footer {
            margin-top: 10px;
            text-align: center;
            font-size: 9pt;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <table style="width: 100%; border-collapse: collapse; border-bottom: 2px solid #000;">
        <tr>
            <!-- Logo à gauche -->
            <td style="width: 20%; vertical-align: top; text-align: left; padding: 0;">
                <img src="C:\\wamp64\\www\\isset\\images\\logo.jpeg" alt="Logo" style="width: 80px; height: auto;">
            </td>
            
            <!-- Texte au centre -->
            <td style="vertical-align: top; text-align: center; width: 60%;">
                <p style="font-size: 14px; line-height: 1.2; font-weight: 500;">
                    Ministère de l'Enseignement Technique de la Formation Professionnelle et de l'Industrie
                </p>

                <h3 style="font-size: 16px; line-height: 1.2; text-transform: uppercase;">
                    BULLETIN DE NOTES DU <?= htmlspecialchars($periode['nom']) ?>
                </h3>
                <strong style="font-size: 13px; line-height: 1.3; text-transform: uppercase;">
                    Année scolaire <?= date('Y') ?>-<?= date('Y') + 1 ?>
                </strong>
            </td>
            
            <!-- Sceau à droite -->
            <td style="width: 20%; vertical-align: top; text-align: right; padding: 0;">
                <img src="C:\\wamp64\\www\\isset\\images\\sceau.png" alt="Sceau" style="width: 80px; height: auto; margin-left: auto;">
            </td>
        </tr>
    </table>

    <!-- Informations élève -->
    <div style="margin: 0; padding: 0;">
    <table class="info-eleve">
        <tr>
            <td style="padding: 5px; margin: 0;"><strong>Nom :</strong> <?= htmlspecialchars($eleve['nom']) ?></td>
            <td style="padding: 5px; margin: 0;"><strong>Prénom :</strong> <?= htmlspecialchars($eleve['prenom']) ?></td>
            <td style="padding: 5px; margin: 0;"><strong>Classe :</strong> <?= htmlspecialchars($classe['nom'].' '.$classe['niveau']) ?></td>
        </tr>
        <tr>
            <td style="padding: 5px; margin: 0;"><strong>Lieu de naissance :</strong> <?= htmlspecialchars($eleve['lieu_naissance'] ?? '') ?></td>
            <td style="padding: 5px; margin: 0;"><strong>Date de naissance :</strong> <?php 
                if (!empty($eleve['date_naissance'])) {
                    $date = date_create_from_format('Y-m-d', $eleve['date_naissance']);
                    echo $date ? date_format($date, 'd/m/Y') : htmlspecialchars($eleve['date_naissance']);
                } else {
                    echo '';
                }
            ?></td>
            <td style="padding: 5px; margin: 0;"><strong>Effectif de la classe :</strong> <?= htmlspecialchars($effectif) ?></td>
        </tr>
    </table>
    </div>

    <!-- Tableau des notes -->
    <div style="margin: 0; padding: 0;">
        <table class="notes" style="width: 100%; border-collapse: collapse; border: none;">
            <thead style="background-color: #f2f2f2; font-weight: bold; border-top: 2px solid #000;">
                <tr>
                    <th style="text-align: center;font-weight:bold; width: 25%;">Matières</th>
                    <th style="text-align: center;font-weight:bold; width: 8%;">Note 1</th>
                    <th style="text-align: center;font-weight:bold; width: 8%;">Note 2</th>
                    <th style="text-align: center;font-weight:bold; width: 8%;">Devoir</th>
                    <th style="text-align: center;font-weight:bold; width: 8%;">Compo</th>
                    <th style="text-align: center;font-weight:bold; width: 9%;">Moyenne</th >
                    <th style="text-align: center;font-weight:bold; width: 6%;">Coef</th>
                    <th style="text-align: center;font-weight:bold; width: 8%;">Total</th>
                    <th style="text-align: center;font-weight:bold; width: 8%;">Rang</th>
                    <th style="text-align: center;font-weight:bold; width: 12%;">Prof</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($bulletins)): ?>
                    <?php foreach ($bulletins as $b): ?>
                        <tr>
                            <td style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 11px; padding: 5px; width: 25%;"><?= htmlspecialchars($b['matiere_nom']) ?></td>
                            <td style="text-align: center; width: 8%;"><?= htmlspecialchars($b['interro1']) ?></td>
                            <td style="text-align: center; width: 8%;"><?= htmlspecialchars($b['interro2']) ?></td>
                            <td style="text-align: center; width: 8%;"><?= htmlspecialchars($b['devoir']) ?></td>
                            <td style="text-align: center; width: 8%;"><?= htmlspecialchars($b['compo']) ?></td>
                            <td style="text-align: center; width: 9%;"><strong><?= htmlspecialchars($b['moyenne']) ?></strong></td>
                            <td style="text-align: center; width: 6%;"><?= htmlspecialchars($b['coefficient']) ?></td>
                            <td style="text-align: center; width: 8%;"><?= htmlspecialchars($b['coefficient']) * htmlspecialchars($b['moyenne']) ?></td>
                            <td style="text-align: center; width: 8%;"><?= htmlspecialchars($b['rang_matiere']) ?><?php if ($b['rang_matiere'] == 1) { echo '<sup>er</sup>'; } else { echo '<sup>ème</sup>'; } ?></td>
                            <td style="text-align: center; width: 12%; font-size: 10px;"><?= htmlspecialchars($b['professeur']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10">Aucune note enregistrée.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Résultats -->
        <div style="margin: 20px 0;">
            <table class="resultats">
                <tr>
                    <td style="text-align: center;"><strong>Moyenne Générale : <?= htmlspecialchars($moyenne_generale) ?>/20</strong></td>
                        <td style="text-align: center;"><strong>Rang : <?php if ($rang == '1') { echo $rang.'<sup>er</sup>'; } else { echo $rang.'<sup>ème</sup>'; } ?></strong></td>
                    </tr>
            </table>
        </div>
    </div>
    
    <!-- Appréciation générale -->
    <div style="border: 1px solid #ddd; padding: 12px; background-color: #f9f9f9;">
        <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333; padding-bottom: 5px;">
            APPRÉCIATION GÉNÉRALE
        </h4>
        <div style="min-height: 60px; padding: 8px; font-size: 13px; line-height: 1.5;">
            <?= nl2br(htmlspecialchars($appreciation)) ?>
        </div>
    </div>
    <div style="margin-top: 20px;"></div>
    <!-- Signatures -->
    <table class="signatures">
        <tr>
            <td>Le Directeur<br><br>__________________________</td>
            <td>Le Titulaire de classe<br><br>__________________________</td>
        </tr>
    </table>

    <!-- Pied de page -->
    <div class="footer">
        <br>
        <em>Fait à <?= htmlspecialchars($ecole['ville']) ?>, le <?= date('d/m/Y') ?></em>
        <br>
        <em><?= htmlspecialchars($ecole['nom']) ?> - <?= htmlspecialchars($ecole['ville']) ?> - <?= htmlspecialchars($ecole['pays']) ?></em>
        <br>
        <em>Tel : 23300208</em> <span style="margin-left: 10px;">BP : 220</span>

    </div>

</body>
</html>
