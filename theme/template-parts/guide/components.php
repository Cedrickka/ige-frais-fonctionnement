<?php
/**
 * Contenu du guide des composants.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

$swatches = array(
	array( 'Bleu institutionnel', '#17418a', 'En-tête, menus, titres, bouton principal bleu', '--ige-bleu-inst' ),
	array( 'Bleu drapeau', '#0095c9', 'Liens actifs, graphiques, focus', '--ige-bleu' ),
	array( 'Jaune drapeau', '#fff24b', 'Action principale (texte bleu foncé, jamais blanc)', '--ige-jaune' ),
	array( 'Rouge drapeau', '#db3832', 'Rejet, erreur, dépassement', '--ige-rouge' ),
	array( 'Vert', '#65b32e', 'Validée, conforme', '--ige-vert' ),
	array( 'Orange', '#ed7016', 'À vérifier, alerte', '--ige-orange' ),
	array( 'Anthracite', '#323230', 'Texte courant', '--ige-ink' ),
	array( 'Gris clair', '#afb8be', 'Bordures de champs', '--ige-gris' ),
);
?>
<div class="ige-grid">

	<section class="ige-card">
		<h2><?php esc_html_e( 'Couleurs de la charte', 'ige-ff' ); ?> <span class="ige-sub"><?php esc_html_e( 'variables CSS', 'ige-ff' ); ?></span></h2>
		<div class="ige-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
			<?php foreach ( $swatches as $s ) : ?>
				<div style="border:1px solid var(--ige-ligne);border-radius:8px;overflow:hidden">
					<div style="height:56px;background:<?php echo esc_attr( $s[1] ); ?>"></div>
					<div style="padding:10px;font-size:12.5px"><b><?php echo esc_html( $s[0] ); ?></b><br><span class="ige-code"><?php echo esc_html( $s[3] ); ?></span> <?php echo esc_html( $s[1] ); ?><br><span class="ige-muted"><?php echo esc_html( $s[2] ); ?></span></div>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="ige-card">
		<h2><?php esc_html_e( 'Logotype', 'ige-ff' ); ?></h2>
		<div class="ige-grid ige-cols-2">
			<div style="padding:20px;border:1px solid var(--ige-ligne);border-radius:8px"><?php echo ige_ff_logo( 'couleur', false, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div style="padding:20px;background:var(--ige-bleu-inst);border-radius:8px"><?php echo ige_ff_logo( 'blanc', false, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</div>
		<p class="ige-small ige-muted"><?php esc_html_e( 'Couleur sur fond blanc, blanc sur fond coloré. Ordre imposé : armoiries, Ligne d’État (bleu, jaune, rouge de haut en bas), intitulé. Fonction : ige_ff_logo( $variante, $petit, $lien ).', 'ige-ff' ); ?></p>
	</section>

	<section class="ige-card">
		<h2><?php esc_html_e( 'Boutons', 'ige-ff' ); ?></h2>
		<div class="ige-row">
			<button class="ige-btn ige-btn-jaune" type="button"><?php esc_html_e( 'Enregistrer', 'ige-ff' ); ?></button>
			<button class="ige-btn ige-btn-bleu" type="button"><?php esc_html_e( 'Se connecter', 'ige-ff' ); ?></button>
			<button class="ige-btn ige-btn-line" type="button"><?php esc_html_e( 'Exporter', 'ige-ff' ); ?></button>
			<button class="ige-btn ige-btn-rouge" type="button"><?php esc_html_e( 'Rejeter', 'ige-ff' ); ?></button>
			<span style="background:var(--ige-bleu-inst-2);padding:8px;border-radius:8px"><button class="ige-btn ige-btn-ghost" type="button"><?php esc_html_e( 'Sur fond bleu', 'ige-ff' ); ?></button></span>
		</div>
		<p class="ige-small ige-muted"><code>ige-btn ige-btn-jaune | ige-btn-bleu | ige-btn-line | ige-btn-rouge | ige-btn-ghost</code></p>
	</section>

	<div class="ige-grid ige-kpis">
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ige_ff_kpi( array( 'label' => 'Budget consommé', 'value' => '238 940 000', 'unit' => 'CDF', 'percent' => 57.9, 'meta' => '57,9 % de 412 500 000 CDF', 'tone' => 'accent' ) );
		echo ige_ff_kpi( array( 'label' => 'Solde de caisse', 'value' => '2 535 000', 'unit' => 'CDF', 'meta' => 'Arrêté de septembre conforme', 'tone' => 'ok' ) );
		echo ige_ff_kpi( array( 'label' => 'À vérifier', 'value' => '37', 'unit' => 'écritures', 'meta' => '5 avec anomalie', 'tone' => 'alerte' ) );
		echo ige_ff_kpi( array( 'label' => 'Avances à justifier', 'value' => '18 450 000', 'unit' => 'CDF', 'meta' => 'dont 4 200 000 depuis plus de 90 jours', 'tone' => 'risque' ) );
		// phpcs:enable
		?>
	</div>

	<div class="ige-grid ige-cols-2">
		<section class="ige-card">
			<h2><?php esc_html_e( 'Pastilles d’état', 'ige-ff' ); ?></h2>
			<div class="ige-row">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
				echo ige_ff_pill( 'Validée', 'ok' ) . ige_ff_pill( 'À vérifier', 'wait' ) . ige_ff_pill( 'Rejetée', 'ko' ) . ige_ff_pill( 'Mise à jour', 'info' ) . ige_ff_pill( 'Nouvelle', 'neutre' );
				// phpcs:enable
				?>
			</div>
			<p class="ige-small ige-muted"><?php esc_html_e( 'Toujours un libellé : la couleur seule ne porte jamais l’information. Fonction : ige_ff_pill( $libelle, $etat ).', 'ige-ff' ); ?></p>
		</section>
		<section class="ige-card">
			<h2><?php esc_html_e( 'Bannières', 'ige-ff' ); ?></h2>
			<div class="ige-grid" style="gap:8px">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
				echo ige_ff_banner( 'ok', 'Caisse conforme', 'Aucun écart constaté.' );
				echo ige_ff_banner( 'info', 'Contrôle de disponible actif', 'Une sortie qui dépasse le disponible est refusée.' );
				echo ige_ff_banner( 'warn', 'Avance justifiée à 58 %', 'Le seuil requis est de 80 %.' );
				echo ige_ff_banner( 'ko', '1 écriture rejetée à corriger', 'Correction par contrepassation.' );
				// phpcs:enable
				?>
			</div>
		</section>
	</div>

	<section class="ige-card">
		<h2><?php esc_html_e( 'Tableau', 'ige-ff' ); ?> <span class="ige-sub"><code>ige-table-wrap › table.ige-table</code></span></h2>
		<div class="ige-toolbar"><span class="ige-chip ige-on">À vérifier (37)</span><span class="ige-chip">Avec anomalie (5)</span><span class="ige-chip">Validées</span></div>
		<div class="ige-table-wrap">
			<table class="ige-table ige-num">
				<thead><tr><th>N°</th><th>Date</th><th>Rubrique</th><th>Description</th><th class="ige-r">Montant</th><th>État</th><th></th></tr></thead>
				<tbody>
					<tr class="ige-sel"><td>000143</td><td>21/10</td><td>R01</td><td>Rames de papier, classeurs</td><td class="ige-r">1 280 000</td><td><?php echo ige_ff_pill( 'À vérifier', 'wait' ); // phpcs:ignore ?></td><td><a class="ige-act" href="#">Voir</a></td></tr>
					<tr class="ige-rejet"><td>000131</td><td>14/10</td><td>R08</td><td>Réception délégation</td><td class="ige-r">650 000</td><td><?php echo ige_ff_pill( 'Rejetée', 'ko' ); // phpcs:ignore ?></td><td><a class="ige-act" href="#">Contrepasser</a></td></tr>
					<tr><td>000128</td><td>10/10</td><td>R06</td><td>Recharge internet</td><td class="ige-r">300 000</td><td><?php echo ige_ff_pill( 'Validée', 'ok' ); // phpcs:ignore ?></td><td><a class="ige-act" href="#">Voir</a></td></tr>
					<tr class="ige-total"><td></td><td></td><td></td><td>Total</td><td class="ige-r">2 230 000</td><td></td><td></td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<div class="ige-grid ige-cols-2">
		<section class="ige-card">
			<h2><?php esc_html_e( 'Formulaire', 'ige-ff' ); ?></h2>
			<div class="ige-field"><label for="g1">Rubrique</label><select id="g1"><option>R03 · Carburant véhicules de service</option></select></div>
			<div class="ige-field"><label for="g2">Montant (CDF)</label><input id="g2" value="520 000" inputmode="numeric"><span class="ige-help">Espèces autorisées jusqu’à 2 000 000 CDF par paiement.</span></div>
			<div class="ige-field ige-has-error"><label for="g3">N° de pièce</label><input id="g3" value=""><span class="ige-error">Obligatoire pour une sortie.</span></div>
			<div class="ige-photo"><span class="ige-ic">◉</span>Photographier la pièce justificative</div>
			<button class="ige-btn ige-btn-jaune ige-btn-block" type="button">Enregistrer l’écriture</button>
		</section>
		<section class="ige-card ige-detail">
			<h2><?php esc_html_e( 'Suivi et visas', 'ige-ff' ); ?></h2>
			<ol class="ige-timeline">
				<li class="ige-ok"><b>Chef de service</b><span>Visé le 22/10 à 15 h 10</span></li>
				<li class="ige-cur"><b>Comptable</b><span>En attente</span></li>
				<li><b>Inspecteur Général</b><span>Requis au-delà de 5 000 000 CDF</span></li>
			</ol>
			<div class="ige-check"><span class="ige-ok">✓</span>Ordre de mission signé</div>
			<div class="ige-check"><span class="ige-ko">✕</span>Rapport de mission</div>
			<div class="ige-msg"><b>admin.af · 21/10</b><br>Trois devis comparés, offre la moins chère retenue.</div>
		</section>
	</div>

	<section class="ige-card">
		<h2><?php esc_html_e( 'Exécution budgétaire', 'ige-ff' ); ?> <span class="ige-sub"><code>ige-exec</code></span></h2>
		<div class="ige-exec ige-num">
			<span>8000 IG</span><div class="ige-track"><div class="ige-fill" style="width:62%"></div><div class="ige-temps" style="left:81%"></div></div><span>62 %</span>
			<span>8000C OF</span><div class="ige-track"><div class="ige-fill ige-haut" style="width:88%"></div><div class="ige-temps" style="left:81%"></div></div><span>88 %</span>
			<span>8000C CAJ</span><div class="ige-track"><div class="ige-fill ige-depasse" style="width:100%"></div><div class="ige-temps" style="left:81%"></div></div><span class="ige-neg"><b>104 %</b></span>
		</div>
		<div class="ige-legend"><span><i style="background:var(--ige-bleu)"></i>Dans le rythme</span><span><i style="background:var(--ige-orange)"></i>En avance sur le temps écoulé</span><span><i style="background:var(--ige-rouge)"></i>Budget dépassé</span></div>
	</section>

	<section class="ige-card">
		<h2><?php esc_html_e( 'Chaîne de la dépense', 'ige-ff' ); ?> <span class="ige-sub"><code>ige-chain</code></span></h2>
		<div class="ige-chain">
			<div class="ige-ok"><span class="ige-n">1</span><b>Engagement</b><small>02/10 · bon de commande</small></div>
			<div class="ige-ok"><span class="ige-n">2</span><b>Liquidation</b><small>15/10 · service fait</small></div>
			<div class="ige-cur"><span class="ige-n">3</span><b>Ordonnancement</b><small>1 visa sur 2</small></div>
			<div><span class="ige-n">4</span><b>Paiement</b><small>après ordonnancement</small></div>
		</div>
	</section>

	<section class="ige-card">
		<h2><?php esc_html_e( 'Typographie', 'ige-ff' ); ?></h2>
		<p style="font-size:26px;font-weight:700;margin:0">Cooper Hewitt Bold — titres et chiffres clés</p>
		<p style="font-size:15px;margin:6px 0">Cooper Hewitt Book — textes et tableaux de l’interface, chiffres alignés : 1 234 567 CDF</p>
		<p style="font-family:var(--ige-font-doc);font-size:17px;margin:6px 0">EB Garamond — corps des rapports et procès-verbaux (papeterie administrative)</p>
	</section>
</div>
