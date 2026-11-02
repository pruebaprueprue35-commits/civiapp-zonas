<form role="search" method="get" class="cancha-search-form" action="<?php echo esc_url(home_url('/')); ?>">
<label for="cancha-search-field">Nombre de jugador, equipo, campeonato o noticia</label>
<div class="search-form-row"><input id="cancha-search-field" type="search" name="s" placeholder="Ej.: Atlético, Juan Pérez, Copa..." value="<?php echo esc_attr(get_search_query()); ?>" minlength="2" required><button class="button primary" type="submit">Buscar</button></div></form>
