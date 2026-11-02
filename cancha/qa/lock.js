const {chromium}=require('playwright');const {execSync}=require('child_process');
const W=c=>execSync(`cd /home/user/staging/wordpress && php ../wpcli.phar --allow-root ${c}`,{encoding:'utf8'}).trim();
(async()=>{const b=await chromium.launch();const c=await b.newContext({ignoreHTTPSErrors:true,serviceWorkers:'block',storageState:__dirname+'/staging-user.json'});const p=await c.newPage();
await p.goto('https://localhost:8443/predicciones/?tipo=amateur');
const card=(await p.$$('.cancha-pred-card[data-origin=amateur]'))[1];const mid=await card.$eval('input[name=match_id]',e=>e.value);
await Promise.all([p.waitForNavigation(),card.$eval('button[value="1"]',b=>b.click())]);
console.log('guardado previo:',await p.innerText('.cancha-pred-notice'));
await p.goto('https://localhost:8443/predicciones/?tipo=amateur');
const card2=await p.$(`.cancha-pred-card input[name=match_id][value="${mid}"]`);const form=await card2.evaluateHandle(e=>e.form);
const orig=W(`post meta get ${mid} _cancha_start`);
W(`post meta update ${mid} _cancha_start ${new Date(Date.now()-3*3600e3-60e3).toISOString().slice(0,16)}`); // ya empezó (hora AR)
await Promise.all([p.waitForNavigation(),form.evaluate(f=>{const b=f.querySelector('button[value="2"]');b.click();})]);
console.log('intento tras el inicio:',await p.innerText('.cancha-pred-notice'));
console.log('pick en base:',W(`eval 'global $wpdb; echo $wpdb->get_var("SELECT pick FROM {$wpdb->prefix}cancha_predictions WHERE match_id=${mid}");'`));
console.log('visible en lista abierta:',!!(await p.$(`.cancha-pred-card input[name=match_id][value="${mid}"]`)));
// Resultado validado: 2-1 (gana local = '1') → puntos; recalcular dos veces no duplica.
W(`post meta update ${mid} _cancha_home_score 2`);W(`post meta update ${mid} _cancha_away_score 1`);W(`post meta update ${mid} _cancha_result_status final`);
W(`eval 'cancha_pred_sync_match(${mid});cancha_pred_sync_match(${mid});do_action("cancha_pred_reconcile");'`);
console.log('puntos tras 3 recalculos:',W(`eval 'global $wpdb; echo $wpdb->get_var("SELECT SUM(points) FROM {$wpdb->prefix}cancha_predictions WHERE match_id=${mid}");'`));
W(`post meta update ${mid} _cancha_away_score 3`);W(`eval 'cancha_pred_sync_match(${mid});'`);
console.log('corrección 2-3 (gana visita) → puntos:',W(`eval 'global $wpdb; echo $wpdb->get_var("SELECT SUM(points) FROM {$wpdb->prefix}cancha_predictions WHERE match_id=${mid}");'`));
await b.close();})();
