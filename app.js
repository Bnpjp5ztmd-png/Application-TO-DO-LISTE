document.addEventListener('DOMContentLoaded', () => {
    const formulaireTache = document.getElementById('formulaire_tache');
    const champIdTache = document.getElementById('tache_id');
    const champTitreTache = document.getElementById('tache_titre');
    const champDescriptionTache = document.getElementById('tache_description');
    const champEcheanceTache = document.getElementById('tache_echeance');
    const champPrioriteTache = document.getElementById('tache_priorite');
    const boutonAnnulerModification = document.getElementById('annuler_modification_tache');

    const filtreStatut = document.getElementById('filtre_statut');
    const filtrePriorite = document.getElementById('filtre_priorite');
    const triTaches = document.getElementById('tri_taches');
    const selectionParPage = document.getElementById('par_page');

    const corpsTaches = document.getElementById('corps_taches');
    const chargementTaches = document.getElementById('chargement_taches');
    const erreurTaches = document.getElementById('erreur_taches');

    const boutonPagePrecedente = document.getElementById('pagination_precedent');
    const boutonPageSuivante = document.getElementById('pagination_suivant');
    const infoPagination = document.getElementById('pagination_info');

    let pageActuelle = 1;
    let nombreTotalPages = 1;
    let tachesActuelles = [];

    function definirChargement(estEnChargement) {
        if (!chargementTaches) return;
        chargementTaches.classList.toggle('hidden', !estEnChargement);
    }

    function afficherErreur(message) {
        if (!erreurTaches) return;
        erreurTaches.textContent = message;
        erreurTaches.classList.remove('hidden');
    }

    function effacerErreur() {
        if (!erreurTaches) return;
        erreurTaches.textContent = '';
        erreurTaches.classList.add('hidden');
    }

    async function chargerTaches(page = 1) {
        definirChargement(true);
        effacerErreur();

        const valeurParPage = selectionParPage ? selectionParPage.value : '10';
        const parametres = new URLSearchParams();
        parametres.set('action', 'lister_taches');
        parametres.set('page', page.toString());
        parametres.set('par_page', valeurParPage);

        try {
            const reponse = await fetch('api.php?' + parametres.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!reponse.ok) {
                throw new Error('Erreur HTTP ' + reponse.status);
            }

            const json = await reponse.json();
            if (!json.succes) {
                throw new Error(json.erreur || 'Erreur lors du chargement des tâches');
            }

            tachesActuelles = json.donnees.taches || [];
            pageActuelle = json.donnees.pagination.page;
            nombreTotalPages = json.donnees.pagination.nombre_pages;

            if (selectionParPage) {
                const parPageApi = json.donnees.pagination.par_page;
                selectionParPage.value = parPageApi === 'toutes' ? 'toutes' : String(parPageApi);
            }

            afficherTaches();
            mettreAJourPagination();
        } catch (erreur) {
            console.error(erreur);
            afficherErreur(erreur.message);
        } finally {
            definirChargement(false);
        }
    }

    function obtenirTachesFiltreesEtTriees() {
        let taches = [...tachesActuelles];

        const statutChoisi = filtreStatut ? filtreStatut.value : 'toutes';
        const prioriteChoisie = filtrePriorite ? filtrePriorite.value : 'toutes';
        const triChoisi = triTaches ? triTaches.value : 'date_creation';

        taches = taches.filter(tache => {
            if (statutChoisi === 'toutes') return true;
            const estTerminee = Number(tache.est_terminee) === 1;
            if (statutChoisi === 'en_cours') return !estTerminee;
            if (statutChoisi === 'terminees') return estTerminee;
            return true;
        });

        taches = taches.filter(tache => {
            if (prioriteChoisie === 'toutes') return true;
            return tache.priorite === prioriteChoisie;
        });

        taches.sort((a, b) => {
            if (triChoisi === 'date_creation') {
                return new Date(a.date_creation) - new Date(b.date_creation);
            }
            if (triChoisi === 'echeance') {
                if (!a.echeance && !b.echeance) return 0;
                if (!a.echeance) return 1;
                if (!b.echeance) return -1;
                return new Date(a.echeance) - new Date(b.echeance);
            }
            if (triChoisi === 'priorite') {
                const ordre = { 'basse': 1, 'normale': 2, 'haute': 3 };
                return (ordre[a.priorite] || 0) - (ordre[b.priorite] || 0);
            }
            return 0;
        });

        return taches;
    }

    function echeanceDepassee(tache) {
        if (!tache.echeance) return false;
        const estTerminee = Number(tache.est_terminee) === 1;
        if (estTerminee) return false;

        const aujourdHui = new Date();
        aujourdHui.setHours(0, 0, 0, 0);

        const echeance = new Date(tache.echeance);
        echeance.setHours(0, 0, 0, 0);

        return echeance < aujourdHui;
    }

    function afficherTaches() {
        if (!corpsTaches) return;

        corpsTaches.innerHTML = '';
        const taches = obtenirTachesFiltreesEtTriees();

        if (taches.length === 0) {
            const ligne = document.createElement('tr');
            const cellule = document.createElement('td');
            cellule.colSpan = 6;
            cellule.textContent = 'Aucune tâche à afficher.';
            ligne.appendChild(cellule);
            corpsTaches.appendChild(ligne);
            return;
        }

        taches.forEach(tache => {
            const ligne = document.createElement('tr');

            if (echeanceDepassee(tache)) {
                ligne.classList.add('tache-en-retard');
            }

            const estTerminee = Number(tache.est_terminee) === 1;

            const celluleStatut = document.createElement('td');
            const caseStatut = document.createElement('input');
            caseStatut.type = 'checkbox';
            caseStatut.checked = estTerminee;
            caseStatut.addEventListener('change', () => basculerStatutTache(tache.id));
            celluleStatut.appendChild(caseStatut);
            ligne.appendChild(celluleStatut);

            const celluleTitre = document.createElement('td');
            celluleTitre.textContent = tache.titre;
            if (estTerminee) {
                celluleTitre.classList.add('tache-terminee');
            }
            ligne.appendChild(celluleTitre);

            const celluleDescription = document.createElement('td');
            celluleDescription.textContent = tache.description || '-';
            ligne.appendChild(celluleDescription);

            const cellulePriorite = document.createElement('td');
            cellulePriorite.textContent = tache.priorite;
            cellulePriorite.classList.add('priorite-' + tache.priorite);
            ligne.appendChild(cellulePriorite);

            const celluleEcheance = document.createElement('td');
            celluleEcheance.textContent = tache.echeance ? tache.echeance : '-';
            ligne.appendChild(celluleEcheance);

            const celluleActions = document.createElement('td');

            const boutonModifier = document.createElement('button');
            boutonModifier.textContent = 'Modifier';
            boutonModifier.className = 'btn btn-small';
            boutonModifier.addEventListener('click', () => remplirFormulaireModification(tache));
            celluleActions.appendChild(boutonModifier);

            const boutonSupprimer = document.createElement('button');
            boutonSupprimer.textContent = 'Supprimer';
            boutonSupprimer.className = 'btn btn-small btn-danger';
            boutonSupprimer.addEventListener('click', () => {
                if (confirm('Supprimer cette tâche ?')) {
                    supprimerTache(tache.id);
                }
            });
            celluleActions.appendChild(boutonSupprimer);

            ligne.appendChild(celluleActions);
            corpsTaches.appendChild(ligne);
        });
    }

    function mettreAJourPagination() {
        if (!boutonPagePrecedente || !boutonPageSuivante || !infoPagination) return;

        boutonPagePrecedente.disabled = pageActuelle <= 1;
        boutonPageSuivante.disabled = pageActuelle >= nombreTotalPages;
        infoPagination.textContent = `Page ${pageActuelle} / ${nombreTotalPages}`;
    }

    function remplirFormulaireModification(tache) {
        champIdTache.value = tache.id;
        champTitreTache.value = tache.titre;
        champDescriptionTache.value = tache.description || '';
        champEcheanceTache.value = tache.echeance || '';
        champPrioriteTache.value = tache.priorite || 'normale';
        boutonAnnulerModification.classList.remove('hidden');
    }

    function reinitialiserFormulaire() {
        champIdTache.value = '';
        champTitreTache.value = '';
        champDescriptionTache.value = '';
        champEcheanceTache.value = '';
        champPrioriteTache.value = 'normale';
        boutonAnnulerModification.classList.add('hidden');
    }

    async function enregistrerTache(evenement) {
        evenement.preventDefault();
        effacerErreur();

        const id = champIdTache.value ? parseInt(champIdTache.value, 10) : null;
        const donnees = {
            titre: champTitreTache.value.trim(),
            description: champDescriptionTache.value.trim(),
            echeance: champEcheanceTache.value || null,
            priorite: champPrioriteTache.value
        };

        if (!donnees.titre) {
            afficherErreur('Le titre est obligatoire.');
            return;
        }

        const action = id ? 'modifier_tache' : 'creer_tache';
        if (id) {
            donnees.id = id;
        }

        try {
            const reponse = await fetch('api.php?action=' + encodeURIComponent(action), {
                method: id ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(donnees)
            });

            if (!reponse.ok) {
                throw new Error('Erreur HTTP ' + reponse.status);
            }

            const json = await reponse.json();
            if (!json.succes) {
                throw new Error(json.erreur || 'Erreur lors de l\'enregistrement de la tâche');
            }

            reinitialiserFormulaire();
            chargerTaches(pageActuelle);
        } catch (erreur) {
            console.error(erreur);
            afficherErreur(erreur.message);
        }
    }

    async function basculerStatutTache(id) {
        effacerErreur();
        try {
            const reponse = await fetch('api.php?action=basculer_statut_tache', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ id })
            });

            if (!reponse.ok) {
                throw new Error('Erreur HTTP ' + reponse.status);
            }

            const json = await reponse.json();
            if (!json.succes) {
                throw new Error(json.erreur || 'Erreur lors du changement de statut');
            }

            chargerTaches(pageActuelle);
        } catch (erreur) {
            console.error(erreur);
            afficherErreur(erreur.message);
        }
    }

    async function supprimerTache(id) {
        effacerErreur();
        try {
            const reponse = await fetch('api.php?action=supprimer_tache', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ id })
            });

            if (!reponse.ok) {
                throw new Error('Erreur HTTP ' + reponse.status);
            }

            const json = await reponse.json();
            if (!json.succes) {
                throw new Error(json.erreur || 'Erreur lors de la suppression');
            }

            chargerTaches(pageActuelle);
        } catch (erreur) {
            console.error(erreur);
            afficherErreur(erreur.message);
        }
    }

    if (formulaireTache) {
        formulaireTache.addEventListener('submit', enregistrerTache);
    }

    if (boutonAnnulerModification) {
        boutonAnnulerModification.addEventListener('click', () => {
            reinitialiserFormulaire();
        });
    }

    if (filtreStatut) {
        filtreStatut.addEventListener('change', () => {
            afficherTaches();
        });
    }

    if (filtrePriorite) {
        filtrePriorite.addEventListener('change', () => {
            afficherTaches();
        });
    }

    if (triTaches) {
        triTaches.addEventListener('change', () => {
            afficherTaches();
        });
    }

    if (selectionParPage) {
        selectionParPage.addEventListener('change', () => {
            chargerTaches(1);
        });
    }

    if (boutonPagePrecedente) {
        boutonPagePrecedente.addEventListener('click', () => {
            if (pageActuelle > 1) {
                chargerTaches(pageActuelle - 1);
            }
        });
    }

    if (boutonPageSuivante) {
        boutonPageSuivante.addEventListener('click', () => {
            if (pageActuelle < nombreTotalPages) {
                chargerTaches(pageActuelle + 1);
            }
        });
    }

    if (corpsTaches) {
        chargerTaches(1);
    }
});
