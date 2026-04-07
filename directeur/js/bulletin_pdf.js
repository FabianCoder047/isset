document.addEventListener('DOMContentLoaded', function() {
    // Fonction pour télécharger le fichier (méthode classique compatible)
    function downloadFile(blob, suggestedName) {
        try {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = suggestedName;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            return true;
        } catch (err) {
            console.error('Erreur lors du téléchargement du fichier:', err);
            return false;
        }
    }

    // Gestionnaire pour le bouton d'exportation de la classe entière
    const exportClasseBtn = document.getElementById('export-classe-pdf');
    if (exportClasseBtn) {
        exportClasseBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            const classeId = this.getAttribute('data-classe');
            const periodeId = this.getAttribute('data-periode');
            const classeNom = this.getAttribute('data-classe-nom') || 'classe';
            const filename = 'Bulletins_' + classeNom.replace(/\s+/g, '_') + '_' + new Date().toISOString().split('T')[0] + '.zip';
            
            if (confirm('Voulez-vous exporter les bulletins de toute la classe ? Vous pourrez choisir où enregistrer le fichier ZIP.')) {
                // Afficher un indicateur de chargement
                const originalText = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Génération en cours...';
                
                try {
                    // Envoyer une requête pour générer le ZIP
                    const response = await fetch('generer_bulletin_zip.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'export': 'classe',
                            'classe_id': classeId,
                            'periode_id': periodeId,
                            'generate_zip': '1'
                        })
                    });
                    
                    if (!response.ok) {
                        throw new Error('Erreur lors de la génération des bulletins');
                    }
                    
                    // Récupérer le fichier ZIP
                    const blob = await response.blob();
                    
                    // Télécharger directement le fichier
                    const downloaded = downloadFile(blob, filename);
                    
                    if (downloaded) {
                        alert('Les bulletins ont été téléchargés avec succès !');
                    } else {
                        throw new Error('Impossible de télécharger le fichier');
                    }
                    
                } catch (error) {
                    console.error('Erreur:', error);
                    alert('Une erreur est survenue : ' + error.message);
                } finally {
                    // Réactiver le bouton dans tous les cas
                    this.disabled = false;
                    this.innerHTML = originalText;
                }
            }
        });
    }
});
