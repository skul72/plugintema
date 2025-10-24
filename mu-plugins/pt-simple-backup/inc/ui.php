<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_management_page('Backup', 'Backup', 'manage_options', 'pt-simple-backup', 'ptsb_render_backup_page');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'tools_page_pt-simple-backup') {
        wp_enqueue_style('dashicons');
        $base_url = plugin_dir_url(__DIR__ . '/../pt-simple-backup-bs.php');
        $css_path = __DIR__ . '/../assets/admin.css';
        $js_path  = __DIR__ . '/../assets/admin.js';

        wp_enqueue_style(
            'ptsb-admin',
            $base_url . 'assets/admin.css',
            [],
            file_exists($css_path) ? filemtime($css_path) : false
        );

        wp_enqueue_script(
            'ptsb-admin',
            $base_url . 'assets/admin.js',
            [],
            file_exists($js_path) ? filemtime($js_path) : false,
            true
        );
    }
});

add_action('load-tools_page_pt-simple-backup', function () {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCDN')) {
        define('DONOTCDN', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }

    $force = isset($_GET['force']) && (int) $_GET['force'] === 1;
    if ($force && !defined('PTSB_SKIP_MANIFEST_CACHE')) {
        define('PTSB_SKIP_MANIFEST_CACHE', true);
    }

    if (defined('LSCWP_VERSION')) {
        do_action('litespeed_control_set_nocache');
    }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
});

function ptsb_cycle_params_label_ui(array $cycle): string {
    $mode = (string)($cycle['mode'] ?? 'daily');
    $cfg  = is_array($cycle['cfg'] ?? null) ? $cycle['cfg'] : [];

    // Junta SEMPRE: times[] + time (legado) e normaliza
    $times = [];
    if (!empty($cfg['times']) && is_array($cfg['times'])) $times = array_map('strval', $cfg['times']);
    if (!empty($cfg['time'])) $times[] = (string)$cfg['time'];
    $times = array_values(array_filter($times, fn($s)=>trim($s) !== ''));

    if (function_exists('ptsb_times_sort_unique')) {
        $times = ptsb_times_sort_unique($times);
    }

    if ($mode === 'daily') {
        return $times ? implode(', ', $times) : '—';
    }

    if ($mode === 'weekly') {
        $labels = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        $days   = array_map('intval', $cfg['days'] ?? []);
        $days   = array_values(array_filter($days, fn($d)=>$d>=0 && $d<=6));
        $dias   = $days ? implode(', ', array_map(fn($d)=>$labels[$d], $days)) : '—';
        $horas  = $times ? implode(', ', $times) : '—';
        return "{$dias} · {$horas}";
    }

    if ($mode === 'every_n') {
        // >>> NOVO: só horários (sem "N=" e sem "início=")
        $horas = $times ? implode(', ', $times) : '—';
        return $horas;
    }

  if ($mode === 'interval') {
    $u    = strtolower($cfg['every']['unit'] ?? 'minute');
    $u_br = ($u === 'day' ? 'dia(s)' : ($u === 'hour' ? 'hora(s)' : 'minuto(s)'));
    $val  = (int)($cfg['every']['value'] ?? 1);

    $win_on = empty($cfg['win']['disabled']);
    $winTxt = $win_on ? (($cfg['win']['start'] ?? '00:00').'–'.($cfg['win']['end'] ?? '23:59')) : '';

    $base = "cada {$val} {$u_br}";
    return $base . ($win_on ? ' · ' . $winTxt : '');
}


    return '—';
}




function ptsb_render_backup_page() {
    if (!current_user_can('manage_options')) return;

    $cfg     = ptsb_cfg();
    $set     = ptsb_settings();
    $rows    = ptsb_list_remote_files();
    $keepers = ptsb_keep_map();
    $auto    = ptsb_auto_get();

    // === Abas (roteamento) ===
$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backup';
if (!in_array($tab, ['backup','cycles','next','last','settings'], true)) $tab = 'backup';


    $h1 = [
    'backup'    => 'Backups (Google Drive)',
    'cycles'    => 'Rotinas de Backup',
    'next'      => 'Próximas Execuções',
    'last'      => 'Últimas Execuções',
    'settings'  => 'Configurações',
][$tab];

    // Diagnóstico
    $diag = [];
    $diag[] = 'shell_exec '.(ptsb_can_shell() ? 'OK' : 'DESABILITADO');
    $diag[] = 'log '.(ptsb_is_readable($cfg['log']) ? 'legivel' : 'sem leitura');
    $diag[] = 'backup.sh '.(@is_executable($cfg['script_backup']) ? 'executavel' : 'sem permissao');
    $diag[] = 'restore.sh '.(@is_executable($cfg['script_restore']) ? 'executavel' : 'sem permissao');

    $nonce = wp_create_nonce('ptsb_nonce');
    $admin_js_data = [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => $nonce,
        'renamePrefix' => (string)($cfg['prefix'] ?? ''),
    ];

    // Drive (resumo)
  $drive = ptsb_drive_info();

// se vier ?force=1, zera o cache dos totais
if (isset($_GET['force']) && (int)$_GET['force'] === 1) {
    delete_transient('ptsb_totals_v1');
}
$tot    = ptsb_backups_totals_cached();
$bk_count       = (int)$tot['count'];
$backups_total  = (int)$tot['bytes'];

$usedStr  = ($drive['used']  !== null) ? ptsb_hsize_compact($drive['used'])  : '?';
$totalStr = ($drive['total'] !== null) ? ptsb_hsize_compact($drive['total']) : '?';
$bkStr    = number_format_i18n($bk_count) . ' ' . ($bk_count === 1 ? 'item' : 'itens') . ' / ' . ptsb_hsize_compact($backups_total);


    // Navegação das abas
    $base = admin_url('tools.php?page=pt-simple-backup');
   $tabs = [
    'backup'    => 'Backup',
    'cycles'    => 'Rotinas de Backup',
    'next'      => 'Próximas Execuções',
    'last'      => 'Últimas Execuções',
    'settings'  => 'Configurações',
];

    // CSS leve compartilhado (chips + “pílulas”)
    ?>
    <div class="wrap">
      <h1><?php echo esc_html($h1); ?></h1>
      <p style="opacity:.7;margin:.3em 0 1em">
        Armazenamento: <strong><?php echo esc_html($usedStr.' / '.$totalStr); ?></strong> |
        Backups no Drive: <strong><?php echo esc_html($bkStr); ?></strong>
      </p>

      <h2 class="nav-tab-wrapper" style="margin-top:8px">
        <?php foreach ($tabs as $slug => $label):
          $url = esc_url( add_query_arg('tab', $slug, $base) );
          $cls = 'nav-tab' . ($tab === $slug ? ' nav-tab-active' : '');
        ?>
          <a class="<?php echo $cls; ?>" href="<?php echo $url; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
      </h2>
<?php if ($tab === 'backup'): ?>

        <!-- ===== ABA: BACKUP ===== -->

        <h2 style="margin-top:24px !important">Fazer Backup</h2>

 <p class="description">
           Escolha quais partes do site incluir no backup. Para um backup completo, mantenha todos selecionados.
          </p>

        <!-- Disparar manual -->
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" id="ptsb-now-form" style="margin:12px 0;">
          <?php wp_nonce_field('ptsb_nonce'); ?>
          <input type="hidden" name="action" value="ptsb_do"/>
          <input type="hidden" name="ptsb_action" value="backup_now"/>
          <input type="hidden" name="parts_sel[]" value="" id="ptsb-parts-hidden-sentinel" />

       <div class="ptsb-chips" id="ptsb-chips">

  <label class="ptsb-chip" data-letter="D">
    <input type="checkbox" checked data-letter="D">
    <span class="dashicons dashicons-database"></span> Banco de Dados
  </label>
  <label class="ptsb-chip" data-letter="P">
    <input type="checkbox" checked data-letter="P">
    <span class="dashicons dashicons-admin-plugins"></span> Plugins
  </label>
  <label class="ptsb-chip" data-letter="T">
    <input type="checkbox" checked data-letter="T">
    <span class="dashicons dashicons-admin-appearance"></span> Temas
  </label>
  <label class="ptsb-chip" data-letter="W">
    <input type="checkbox" checked data-letter="W">
    <span class="dashicons dashicons-wordpress-alt"></span> Core
  </label>
  <label class="ptsb-chip" data-letter="S">
    <input type="checkbox" checked data-letter="S">
    <span class="dashicons dashicons-editor-code"></span> Scripts
  </label>
  <label class="ptsb-chip" data-letter="M">
    <input type="checkbox" checked data-letter="M">
    <span class="dashicons dashicons-admin-media"></span> Mídia
  </label>
  <label class="ptsb-chip" data-letter="O">
    <input type="checkbox" checked data-letter="O">
    <span class="dashicons dashicons-image-filter"></span> Outros
  </label>
</div>


       

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 2px">
            <label>Nome do backup:
              <input type="text" name="manual_name" placeholder="Opcional" style="min-width:280px">
            </label>
            <label>Armazenar por quantos dias?
              <input type="number" name="manual_keep_days" min="1" max="3650"
                     placeholder="Máx: 3650" required style="width:120px">
            </label>
            <div class="ptsb-keep-toggle" style="align-self:flex-end;margin-top:4px">
  <label class="ptsb-switch" title="Sempre manter">
    <input type="checkbox" id="ptsb-man-keep-forever" name="manual_keep_forever" value="1">
    <span class="ptsb-slider" aria-hidden="true"></span>
  </label>
  <span class="ptsb-keep-txt">Sempre manter</span>
</div>

          </div>

         <div class="ptsb-btns" style="margin-top: 18px;">
  <button class="button button-primary">Fazer backup agora</button>
  <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($cfg['drive_url']); ?>">Ver no Drive</a>
</div>

        </form>

        <!-- Barra de progresso -->
        <div id="ptsb-progress" style="display:none;margin:16px 0;border:1px solid #444;background:#1b1b1b;height:22px;position:relative;border-radius:4px;overflow:hidden;">
          <div id="ptsb-progress-bar" style="height:100%;width:5%;background:#2271b1;transition:width .4s ease"></div>
          <div id="ptsb-progress-text" style="position:absolute;left:8px;top:0;height:100%;line-height:22px;color:#fff;opacity:.9;font-size:12px;">Iniciando…</div>
        </div>

        <!-- Arquivos no Drive -->
       <!-- Arquivos no Drive -->
<h2 style="margin-top:24px !important">Arquivos no Google Drive  <a class="button button-small" style="margin-left:8px"
     href="<?php echo esc_url( add_query_arg(['force'=>1], $base) ); ?>">Forçar atualizar</a></h2>
<?php
  // ====== PAGINAÇÃO (lista do Drive) ======
  $total = count($rows);

  // valor padrão salvo (opcional) + query string
$per_default = (int) get_option('ptsb_list_per_page', 25);
$per = isset($_GET['per']) ? (int) $_GET['per'] : ($per_default > 0 ? $per_default : 25);

  $per = max(1, min($per, 500));              // limite de sanidade
  if (isset($_GET['per'])) update_option('ptsb_list_per_page', $per, false); // lembra preferência

  $paged = max(1, (int)($_GET['paged'] ?? 1));
  $total_pages = max(1, (int) ceil($total / $per));
  if ($paged > $total_pages) $paged = $total_pages;

  $offset    = ($paged - 1) * $per;
  $rows_page = array_slice($rows, $offset, $per);

  // URL base para os links de paginação
  $base_admin = admin_url('tools.php');
  $make_url = function($p) use ($base_admin, $per) {
      return esc_url( add_query_arg([
          'page'  => 'pt-simple-backup',
          'tab'   => 'backup',
          'per'   => $per,
          'paged' => (int) $p
      ], $base_admin) );
  };
  $admin_js_data['backupPagerUrl'] = esc_url_raw(add_query_arg([
      'page'  => 'pt-simple-backup',
      'tab'   => 'backup',
      'per'   => $per,
      'paged' => '__PAGE__',
  ], $base_admin));
?>

<!-- Filtro "Exibindo N de M" -->
 <!-- “Exibindo N de M” -->
  <form method="get" id="ptsb-last-per-form" class="ptsb-list-controls" style="margin:0">
    <input type="hidden" name="page" value="pt-simple-backup">
    <input type="hidden" name="tab"  value="last">
    <input type="hidden" name="page_last" value="1">
    <span>Exibindo</span>
    <input type="number" name="per_last" min="1" max="500" value="<?php echo (int)$per_last; ?>" style="width:auto">
    <span>de <?php echo (int)$total_last; ?> execuções — página <?php echo (int)$page_last; ?> de <?php echo (int)$total_pages_l; ?></span>
  </form>
</div>
<table class="widefat striped">

          <thead>
            <tr>
              <th>Data/Hora</th>
              <th>Arquivo</th>
              <th>Rotina</th>
              <th>Backup</th>
              <th>Tamanho</th>
              <th>Retenção</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
       <?php if ($total === 0): ?>
  <tr><td colspan="7"><em>Nenhum backup encontrado.</em></td></tr>

<?php else:
  foreach ($rows_page as $r):

    $time = $r['time']; $file = $r['file']; $size = (int)($r['size'] ?? 0);
    $is_kept = !empty($keepers[$file]);
    $manifest = ptsb_manifest_read($file);
    $letters = [];
    if (!empty($manifest['parts'])) $letters = ptsb_parts_to_letters($manifest['parts']);
    if (!$letters) $letters = ['D','P','T','W','S','M','O'];
    $keepDays  = ptsb_manifest_keep_days($manifest, (int)$set['keep_days']);
    $rotina_label = ptsb_run_kind_label($manifest, $file);

    // NOVO: calcular se está vencido (não-keep e X/Y >= Y/Y)
    $ri = null; $is_expired = false;
    if (!$is_kept && is_int($keepDays) && $keepDays > 0) {
        $ri = ptsb_retention_calc($time, $keepDays);
        $is_expired = ($ri['x'] >= $ri['y']);
    }
    
    $tr_class = $is_expired ? ' class="ptsb-expired"' : '';
?>
  
<?php
  $time = $r['time']; 
  $file = $r['file']; 
  $size = (int)($r['size'] ?? 0);
  $is_kept = !empty($keepers[$file]); // mantém só o mapa .keep (1 listagem rclone)
?>
<tr data-file="<?php echo esc_attr($file); ?>" 
    data-time="<?php echo esc_attr($time); ?>" 
    data-kept="<?php echo $is_kept ? 1 : 0; ?>">

  <td><?php echo esc_html( ptsb_fmt_local_dt($time) ); ?></td>

  <td>
    <span class="ptsb-filename"><?php echo esc_html($file); ?></span>
    <!-- o badge “vencido” será inserido via JS, se for o caso -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>"
          class="ptsb-rename-form" style="display:inline">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="rename"/>
      <input type="hidden" name="old_file" value="<?php echo esc_attr($file); ?>"/>
      <input type="hidden" name="new_file" value=""/>
      <button type="button" class="ptsb-rename-btn" title="Renomear" data-old="<?php echo esc_attr($file); ?>">
        <span class="dashicons dashicons-edit" aria-hidden="true"></span>
        <span class="screen-reader-text">Renomear</span>
      </button>
    </form>
  </td>

  <!-- ROTINA (placeholder) -->
  <td class="ptsb-col-rotina"><span class="description">carregando…</span></td>

  <!-- BACKUP (letras; placeholder) -->
  <td class="ptsb-col-letters" aria-label="Partes incluídas">
    <span class="description">carregando…</span>
  </td>

  <td><?php echo esc_html( ptsb_hsize($size) ); ?></td>

  <!-- RETENÇÃO (placeholder; “sempre”, X/Y ou “—”) -->
  <td class="ptsb-col-ret"><span class="description">carregando…</span></td>

  <!-- AÇÕES (inalterado) -->
  <td class="ptsb-actions">
    <!-- Restaurar -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:0;"
          onsubmit="return confirm('Restaurar <?php echo esc_js($file); ?>? Isso vai sobrescrever arquivos e banco.');">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <button class="button button-secondary" name="ptsb_action" value="restore" <?php disabled(!ptsb_can_shell()); ?>>Restaurar</button>
    </form>

    <!-- Apagar -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:6px;"
          onsubmit="return confirm('Apagar DEFINITIVAMENTE do Drive: <?php echo esc_js($file); ?>?');">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <button class="button" name="ptsb_action" value="delete"
              <?php disabled(!ptsb_can_shell() || $is_kept); ?>
              <?php echo $is_kept ? 'title="Desative &quot;Sempre manter&quot; antes de apagar"' : ''; ?>>
        Apagar
      </button>
    </form>

    <!-- Toggle “Sempre manter” (inalterado) -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="ptsb-keep-form">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="keep_toggle"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <div class="ptsb-keep-toggle">
        <label class="ptsb-switch" title="<?php echo $is_kept ? 'Desativar' : 'Ativar'; ?>">
          <input type="checkbox" name="keep" value="1" <?php checked($is_kept); ?> onchange="this.form.submit()">
          <span class="ptsb-slider" aria-hidden="true"></span>
        </label>
        <span class="ptsb-keep-txt">Sempre manter</span>
      </div>
    </form>
  </td>
</tr>

  
          <?php endforeach; endif; ?>
          </tbody>
        </table>
<?php if ($total_pages > 1): ?>
  <nav class="ptsb-pager" aria-label="Paginação dos backups">
    <a class="btn <?php echo $paged<=1?'is-disabled':''; ?>"
       href="<?php echo $paged>1 ? $make_url(1) : '#'; ?>" aria-disabled="<?php echo $paged<=1?'true':'false'; ?>"
       title="Primeira página">
      <span class="dashicons dashicons-controls-skipback"></span>
    </a>

    <a class="btn <?php echo $paged<=1?'is-disabled':''; ?>"
       href="<?php echo $paged>1 ? $make_url($paged-1) : '#'; ?>" aria-disabled="<?php echo $paged<=1?'true':'false'; ?>"
       title="Página anterior">
      <span class="dashicons dashicons-arrow-left-alt2"></span>
    </a>

    <span class="status">
      <input id="ptsb-pager-input" class="current" type="number"
             min="1" max="<?php echo (int)$total_pages; ?>" value="<?php echo (int)$paged; ?>">
      <span class="sep">de</span>
      <span class="total"><?php echo (int)$total_pages; ?></span>
    </span>

    <a class="btn <?php echo $paged>=$total_pages?'is-disabled':''; ?>"
       href="<?php echo $paged<$total_pages ? $make_url($paged+1) : '#'; ?>" aria-disabled="<?php echo $paged>=$total_pages?'true':'false'; ?>"
       title="Próxima página">
      <span class="dashicons dashicons-arrow-right-alt2"></span>
    </a>

    <a class="btn <?php echo $paged>=$total_pages?'is-disabled':''; ?>"
       href="<?php echo $paged<$total_pages ? $make_url($total_pages) : '#'; ?>" aria-disabled="<?php echo $paged>=$total_pages?'true':'false'; ?>"
       title="Última página">
      <span class="dashicons dashicons-controls-skipforward"></span>
    </a>
  </nav>
<?php endif; ?>
<?php elseif ($tab === 'cycles'): ?>

  <!-- ===== ABA: ROTINAS ===== -->
  <h2 style="margin-top:22px">Rotinas de backup</h2>

  <!-- NOVO: botão/abridor "Adicionar rotina" (sem ícone) logo abaixo do título -->
  <div style="margin:10px 0 14px;">
  <details>
    <summary class="button button-primary">Adicionar rotina</summary>
    <div style="padding:10px 0">
      <form id="ptsb-add-cycle-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <?php wp_nonce_field('ptsb_nonce'); ?>
        <input type="hidden" name="action" value="ptsb_cycles"/>
        <input type="hidden" name="do" value="save_one"/>

    
<p class="description" style="margin-top:0">
  Selecione as partes do site a incluir no backup. Para um backup completo, mantenha todas selecionadas.
</p>


<div class="ptsb-chips" id="ptsb-add-letters" style="margin-bottom:16px">
  <label class="ptsb-chip" title="Banco de Dados">
    <input type="checkbox" checked data-letter="D">
    <span class="dashicons dashicons-database"></span> Banco de Dados
  </label>
  <label class="ptsb-chip" title="Plugins">
    <input type="checkbox" checked data-letter="P">
    <span class="dashicons dashicons-admin-plugins"></span> Plugins
  </label>
  <label class="ptsb-chip" title="Temas">
    <input type="checkbox" checked data-letter="T">
    <span class="dashicons dashicons-admin-appearance"></span> Temas
  </label>
  <label class="ptsb-chip" title="Core">
    <input type="checkbox" checked data-letter="W">
    <span class="dashicons dashicons-wordpress-alt"></span> Core
  </label>
  <label class="ptsb-chip" title="Scripts">
    <input type="checkbox" checked data-letter="S">
    <span class="dashicons dashicons-editor-code"></span> Scripts
  </label>
  <label class="ptsb-chip" title="Mídia">
    <input type="checkbox" checked data-letter="M">
    <span class="dashicons dashicons-admin-media"></span> Mídia
  </label>
  <label class="ptsb-chip" title="Outros">
    <input type="checkbox" checked data-letter="O">
    <span class="dashicons dashicons-image-filter"></span> Outros
  </label>
</div>
<label>Nome da Rotina:
  <input type="text" name="name" value="" required aria-required="true"
         style="min-width:260px" placeholder="Ex.: Diário completo">
</label>


<label>Armazenar por quantos dias?
  <input type="number" name="keep_days" min="1" max="3650"
         placeholder="Máx: 3650" required style="width:120px">
</label>


<!-- Toggle: Sempre manter -->
<div class="ptsb-keep-toggle" style="margin-left:8px">
  <label class="ptsb-switch" title="Sempre manter">
    <input type="checkbox" name="keep_forever" value="1">
    <span class="ptsb-slider" aria-hidden="true"></span>
  </label>
  <span class="ptsb-keep-txt">Sempre manter</span>
</div>
<br>

         <label class="ptsb-section-gap">Tipo:
  <select name="mode" onchange="this.closest('form').querySelectorAll('[data-new]').forEach(el=>el.style.display='none'); this.closest('form').querySelector('[data-new='+this.value+']').style.display='';">
    <option value="daily" selected>Diário</option>
    <option value="weekly">Semanal</option>
    <option value="every_n">Recorrente</option>
    <option value="interval">Intervalo</option>
  </select>

</label>


          <div data-new="daily">
  <div class="ptsb-inline-field" style="margin-top:6px">Quantos horários por dia?</div>
  <input type="number" name="qty" min="1" max="12" value="3" style="width:80px" id="new-daily-qty">
  <div id="new-daily-times" class="ptsb-times-grid"></div>
</div>

          <div data-new="weekly" style="display:none">
<div class="ptsb-inline-field" style="margin-top:8px">Quantos horários por dia?</div>
  <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-weekly-qty">
  <div id="new-weekly-times" class="ptsb-times-grid"></div>
  <div>
  <p>Defina em quais dias da semana o backup será feito:</p>
</div>
<div class="ptsb-chips" id="wk_new">

  <span class="ptsb-chip" data-day="0" title="Domingo"        aria-label="Domingo">D</span>
  <span class="ptsb-chip" data-day="1" title="Segunda-feira"   aria-label="Segunda-feira">S</span>
  <span class="ptsb-chip" data-day="2" title="Terça-feira"     aria-label="Terça-feira">T</span>
  <span class="ptsb-chip" data-day="3" title="Quarta-feira"    aria-label="Quarta-feira">Q</span>
  <span class="ptsb-chip" data-day="4" title="Quinta-feira"    aria-label="Quinta-feira">Q</span>
  <span class="ptsb-chip" data-day="5" title="Sexta-feira"     aria-label="Sexta-feira">S</span>
  <span class="ptsb-chip" data-day="6" title="Sábado"          aria-label="Sábado">S</span>
</div>
<input type="text" name="wk_days_guard" id="wk_new_guard"
       style="position:absolute;left:-9999px;width:1px;height:1px" tabindex="-1"
         aria-hidden="true" disabled>

</div>



         <div data-new="every_n" style="display:none">




<div class="ptsb-inline-field" style="margin-left:6px">Quantos horários por dia?</div>

  <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-everyn-qty">
<div>
<label style="margin-top:10px;display:inline-block">Repetir a cada quantos dias? <input type="number" min="1" max="30" name="n" value="3" style="width:80px"></label>
</div>
<div id="new-everyn-times" class="ptsb-times-grid"></div>
</div>


          <div data-new="interval" style="display:none">
  <label>Repetir a cada
    <input type="number" name="every_val" value="2" min="1" style="width:48px">
    <select name="every_unit">
      <option value="minute">minuto(s)</option>
      <option value="hour"  selected>hora(s)</option>
      <option value="day">dia(s)</option> <!-- NOVO -->
    </select>
  </label>

  <!-- NOVO: toggle para desativar a janela (ligado por padrão) -->
  <label class="ptsb-keep-toggle" style="margin-left:10px" title="Ignorar início/fim; usar o dia inteiro">
    <label class="ptsb-switch" style="margin-right:6px">
      <input type="checkbox" name="win_disable" value="1" checked>
      <span class="ptsb-slider" aria-hidden="true"></span>
    </label>
    <span class="ptsb-keep-txt">Desativar janela de tempo</span>
  </label>

  <label style="margin-left:10px">Janela:
    <input type="time" name="win_start" value="08:00" style="width:120px"> –
    <input type="time" name="win_end"   value="20:00" style="width:120px">
  </label>
</div>
<input type="hidden" name="policy_one" value="queue">
          <div style="margin-top:10px"><button class="button button-primary">Salvar rotina</button>
 <!-- Toggle "Ativar ao salvar" agora ao lado do botão -->
  <div class="ptsb-keep-toggle" title="Ativar ao salvar">
    <label class="ptsb-switch">
      <input type="checkbox" name="enabled" value="1" checked>
      <span class="ptsb-slider" aria-hidden="true"></span>
    </label>
    <span class="ptsb-keep-txt">Ativar ao salvar</span>
  </div>
</div>

          </div>
        </form>



      </div>
    </details>
  </div>

  <?php
  $cycles = ptsb_cycles_get();
  ?>
  <table class="widefat striped">

          <thead>
            <tr>
              <th>Ativo</th>
              <th>Nome</th>
              <th>Frequência</th>
              <th>Dias e Horários</th>
              <th>Backup</th>
              <th>Retenção</th>
              <th>Próx. execução.</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$cycles): ?>
              <tr><td colspan="8"><em>Nenhuma rotina ainda. Use “Adicionar rotina”.</em></td></tr>
            <?php else:
              foreach ($cycles as $c):
                $cid = esc_attr($c['id']);
                $parts_letters = array_values(array_intersect(array_map('strtoupper', (array)($c['letters']??[])), ['D','P','T','W','S','M','O']));
               $mode = strtolower($c['mode'] ?? 'daily');
if ($mode === 'daily') {
    $freq = 'Diário';
} elseif ($mode === 'weekly') {
    $freq = 'Semanal';
} elseif ($mode === 'every_n') {
    $n = max(1, (int)($c['cfg']['n'] ?? 1));
    // Ex.: "Cada 2 dias / Recorrente"
     $freq = 'Recorrente · A cada ' . $n . ' dias';
} elseif ($mode === 'interval') {
    $freq = 'Intervalo';
} else {
    $freq = ucfirst($mode);
}



$p = ptsb_cycle_params_label_ui($c);  // << usar o helper
$next1 = ptsb_cycles_next_occurrences([$c], 1);
$nx    = $next1 ? esc_html($next1[0]['dt']->format('d/m/Y H:i')) : '(—)';



                $defDays = (int)($set['keep_days'] ?? 0);
            ?>
              <tr>
                <td>
                  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field('ptsb_nonce'); ?>
                    <input type="hidden" name="action" value="ptsb_cycles"/>
                    <input type="hidden" name="do" value="toggle"/>
                    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
                    <label class="ptsb-switch">
                      <input type="checkbox" name="enabled" value="1" <?php checked(!empty($c['enabled'])); ?> onchange="this.form.submit()">
                      <span class="ptsb-slider"></span>
                    </label>
                  </form>
                </td>
                <td><strong><?php echo esc_html($c['name'] ?? ''); ?></strong></td>
                <td><?php echo esc_html($freq); ?></td>
                <td style="white-space:nowrap"><?php echo esc_html($p); ?></td>
                <td>
                  <?php foreach ($parts_letters as $L): $meta = ptsb_letter_meta($L); ?>
                    <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
                      <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td>
                  <?php
                  if (isset($c['keep_days']) && (int)$c['keep_days'] === 0) {
                    echo '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
                  } elseif (isset($c['keep_days']) && (int)$c['keep_days'] > 0) {
                    $d = (int)$c['keep_days'];
                    echo '<span class="ptsb-ret" title="'.esc_attr(sprintf('Reter por %d dias', $d)).'">'.esc_html($d).' d</span>';
                  } else {
                    echo '<span class="ptsb-ret" title="'.esc_attr(sprintf('Padrão do painel: %d dias', $defDays)).'">'.esc_html($defDays).' d</span>';
                  }
                  ?>
                </td>
              <td><?php echo $nx; ?></td>

            <td class="ptsb-actions">
  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:0"
        onsubmit="return confirm('Duplicar esta rotina?');">
    <?php wp_nonce_field('ptsb_nonce'); ?>
    <input type="hidden" name="action" value="ptsb_cycles"/>
    <input type="hidden" name="do" value="dup"/>
    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
    <button class="button">Duplicar</button>
  </form>

  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:6px"
        onsubmit="return confirm('Remover esta rotina?');">
    <?php wp_nonce_field('ptsb_nonce'); ?>
    <input type="hidden" name="action" value="ptsb_cycles"/>
    <input type="hidden" name="do" value="delete"/>
    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
    <button class="button">Remover</button>
  </form>
</td>

            
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
<?php elseif ($tab === 'next'): ?>

        <!-- ===== ABA: PRÓXIMAS EXECUÇÕES (filtro por data + paginação) ===== -->
        <?php
        $cycles  = ptsb_cycles_get();
        $skipmap = ptsb_skipmap_get();

        // ====== CONTROLES ======
        // per/página (1..100), lembrando preferência
        $per_default = (int) get_option('ptsb_next_per_page', 12);
        $per_next = isset($_GET['per_next']) ? (int) $_GET['per_next'] : ($per_default > 0 ? $per_default : 12);
        $per_next = max(1, min($per_next, 100));
        if (isset($_GET['per_next'])) update_option('ptsb_next_per_page', $per_next, false);

        $page_next = max(1, (int)($_GET['page_next'] ?? 1));

        // filtro de data (YYYY-mm-dd)
        $next_date_raw = isset($_GET['next_date']) ? preg_replace('/[^0-9\-]/','', (string)$_GET['next_date']) : '';
        $next_date     = '';
        $dayObj        = null;
        if ($next_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_date_raw)) {
            try { $dayObj = new DateTimeImmutable($next_date_raw.' 00:00:00', ptsb_tz()); $next_date = $next_date_raw; }
            catch (Throwable $e) { $dayObj = null; }
        }

        if ($dayObj) {
    $today0 = ptsb_now_brt()->setTime(0,0);
    if ($dayObj < $today0) {
        $dayObj    = $today0;
        $next_date = $dayObj->format('Y-m-d'); // mantém coerente no input
    }
}

        // Carrega a lista:
        // - com data: todas as ocorrências daquele dia
        // - sem data: estratégia "per * page" (futuro ilimitado)
        if ($cycles) {
            if ($dayObj) {
                $all = ptsb_cycles_occurrences_for_date($cycles, $dayObj);
                $total_loaded = count($all);
                $has_next = false; // sabemos o total do dia; não há "futuro" dentro do mesmo dia
            } else {
                $need  = $per_next * $page_next;
                $all   = ptsb_cycles_next_occurrences($cycles, $need);
                $total_loaded = count($all);
                $has_next = ($total_loaded === $need);
            }
        } else {
            $all=[]; $total_loaded=0; $has_next=false;
        }

        // Fatia para a página atual
        $offset    = ($page_next - 1) * $per_next;
        $rows_page = array_slice($all, $offset, $per_next);

        // Helpers de URL (preservando o filtro de data)
        $base_admin = admin_url('tools.php');
        $make_url = function($p, $per, $date='') use ($base_admin) {
            $args = [
                'page'      => 'pt-simple-backup',
                'tab'       => 'next',
                'per_next'  => (int) $per,
                'page_next' => (int) $p,
            ];
            if ($date) $args['next_date'] = $date;
            return esc_url( add_query_arg($args, $base_admin) );
        };
        $next_args = [
            'page'      => 'pt-simple-backup',
            'tab'       => 'next',
            'per_next'  => $per_next,
            'page_next' => '__PAGE__',
        ];
        if ($next_date) {
            $next_args['next_date'] = $next_date;
        }
        $admin_js_data['nextPagerUrl'] = esc_url_raw(add_query_arg($next_args, $base_admin));
        ?>

        <h2 style="margin-top:8px">Próximas Execuções</h2>

        <?php if (!$cycles): ?>
          <p><em>Sem rotinas ativas.</em></p>
        <?php elseif (!$all): ?>
          <p><em><?php echo $dayObj ? 'Nenhuma execução neste dia.' : 'Nenhuma execução prevista. Confira as rotinas e horários.'; ?></em></p>
        <?php else: ?>

          <!-- Controles: Filtro por data + "Exibindo N por página" -->
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 10px">
            <form method="get" id="ptsb-next-date-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:8px;margin:0">
              <input type="hidden" name="page" value="pt-simple-backup">
              <input type="hidden" name="tab"  value="next">
              <input type="hidden" name="per_next"  value="<?php echo (int)$per_next; ?>">
              <input type="hidden" name="page_next" value="1"><!-- mudar a data volta pra pág. 1 -->
              <span>Ver execuções do dia:</span>
              <input type="date"
       name="next_date"
       value="<?php echo esc_attr($next_date); ?>"
       min="<?php echo esc_attr( ptsb_now_brt()->format('Y-m-d') ); ?>"
       style="width:auto">

              <?php if ($next_date): ?>
                <a class="button" href="<?php echo esc_url( add_query_arg(['page'=>'pt-simple-backup','tab'=>'next','per_next'=>$per_next,'page_next'=>1], $base_admin) ); ?>">Limpar</a>
              <?php endif; ?>
            </form>

            <form method="get" id="ptsb-next-per-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:6px;margin:0">
              <input type="hidden" name="page" value="pt-simple-backup">
              <input type="hidden" name="tab" value="next">
              <?php if ($next_date): ?><input type="hidden" name="next_date" value="<?php echo esc_attr($next_date); ?>"><?php endif; ?>
              <input type="hidden" name="page_next" value="1"><!-- mudar per volta pra pág. 1 -->
              <span>Exibindo</span>
              <input type="number" name="per_next" min="1" max="100" value="<?php echo (int)$per_next; ?>" style="width:auto">
              <span>próximas execuções — página <?php echo (int)$page_next; ?></span>
            </form>
          </div>
<table class="widefat striped">
            <thead>
              <tr>
                <th>Data/Hora</th>
                <th>Rotinas</th>
                <th>Backup</th>
                <th>Ignorar</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows_page as $it):
              $dtKey = $it['dt']->format('Y-m-d H:i');
              $isIgnored = !empty($skipmap[$dtKey]);
            ?>
              <tr>
                <td><?php echo esc_html( $it['dt']->format('d/m/Y H:i') ); ?></td>
                <td><?php echo esc_html( implode(' + ', (array)$it['names']) ); ?></td>
                <td>
                  <?php foreach ((array)$it['letters'] as $L): $meta = ptsb_letter_meta($L); ?>
                    <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
                      <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td>
                  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline">
                    <?php wp_nonce_field('ptsb_nonce'); ?>
                    <input type="hidden" name="action" value="ptsb_cycles"/>
                    <input type="hidden" name="do" value="skip_toggle"/>
                    <input type="hidden" name="time" value="<?php echo esc_attr($dtKey); ?>"/>
                    <div class="ptsb-keep-toggle">
                      <label class="ptsb-switch" title="<?php echo $isIgnored ? 'Recolocar esta execução' : 'Ignorar esta execução'; ?>">
                        <input type="checkbox" name="skip" value="1" <?php checked($isIgnored); ?> onchange="this.form.submit()">
                        <span class="ptsb-slider" aria-hidden="true"></span>
                      </label>
                      <span class="ptsb-keep-txt">Ignorar esta execução</span>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <!-- Paginação: primeira / anterior / próxima -->
          <nav class="ptsb-pager" aria-label="Paginação das próximas execuções">
            <?php
              $is_first = ($page_next <= 1);
              $prev_url = $is_first ? '#' : $make_url($page_next - 1, $per_next, $next_date);
              // quando há filtro de data, $has_next é false; a paginação considera apenas os itens do dia
              $has_next_effective = $dayObj ? (($offset + $per_next) < $total_loaded) : $has_next;
              $next_url = $has_next_effective ? $make_url($page_next + 1, $per_next, $next_date) : '#';
            ?>
            <a class="btn <?php echo $is_first?'is-disabled':''; ?>"
               href="<?php echo $is_first ? '#' : $make_url(1, $per_next, $next_date); ?>" aria-disabled="<?php echo $is_first?'true':'false'; ?>"
               title="Primeira página">
              <span class="dashicons dashicons-controls-skipback"></span>
            </a>

            <a class="btn <?php echo $is_first?'is-disabled':''; ?>"
               href="<?php echo $prev_url; ?>" aria-disabled="<?php echo $is_first?'true':'false'; ?>"
               title="Página anterior">
              <span class="dashicons dashicons-arrow-left-alt2"></span>
            </a>

            <span class="status">
              <input id="ptsb-next-pager-input" class="current" type="number" min="1" value="<?php echo (int)$page_next; ?>">
              <span class="sep">página</span>
            </span>

            <a class="btn <?php echo !$has_next_effective?'is-disabled':''; ?>"
               href="<?php echo $next_url; ?>" aria-disabled="<?php echo !$has_next_effective?'true':'false'; ?>"
               title="Próxima página">
              <span class="dashicons dashicons-arrow-right-alt2"></span>
            </a>
          </nav>
<?php endif; ?>



      <?php elseif ($tab === 'last'): ?>

  <!-- ===== ABA: ÚLTIMAS EXECUÇÕES (com filtro "Exibindo N" + paginação) ===== -->
  <?php

  // Filtros: mostrar vencidos e/ou em dia (padrão: ambos ligados)
$last_exp = isset($_GET['last_exp']) ? (int)!!$_GET['last_exp'] : 1; // 0 ou 1
$last_ok  = isset($_GET['last_ok'])  ? (int)!!$_GET['last_ok']  : 1; // 0 ou 1


 // >>> ADIÇÃO: parâmetros de paginação desta aba
  $per_default_l = (int) get_option('ptsb_last_per_page', 12);
  $per_last = isset($_GET['per_last']) ? (int) $_GET['per_last'] : ($per_default_l > 0 ? $per_default_l : 12);
  $per_last = max(1, min($per_last, 500));
  if (isset($_GET['per_last'])) update_option('ptsb_last_per_page', $per_last, false);

  $page_last = max(1, (int)($_GET['page_last'] ?? 1));

   // 1) filtra por vencidos/ok
$filtered = [];
foreach ($rows as $r) {
  $time = $r['time']; $file = $r['file'];
  $is_kept  = !empty($keepers[$file]);

  $manifest = ptsb_manifest_read($file);
  $keepDays = ptsb_manifest_keep_days($manifest, (int)$set['keep_days']);

  $is_expired = false;
  if (!$is_kept && is_int($keepDays) && $keepDays > 0) {
      $ri = ptsb_retention_calc($time, $keepDays);
      $is_expired = ($ri['x'] >= $ri['y']);
  }

  // aplica o filtro
  if ( ($is_expired && !$last_exp) || (!$is_expired && !$last_ok) ) {
      continue;
  }
  $filtered[] = $r;
}

$total_last   = count($filtered);
$total_pages_l= max(1, (int) ceil($total_last / $per_last));
if ($page_last > $total_pages_l) $page_last = $total_pages_l;

$offset_last = ($page_last - 1) * $per_last;
$rows_last   = array_slice($filtered, $offset_last, $per_last);


    $base_admin = admin_url('tools.php');
$make_url_l = function($p, $per) use ($base_admin, $last_exp, $last_ok) {
  return esc_url( add_query_arg([
    'page'      => 'pt-simple-backup',
    'tab'       => 'last',
    'per_last'  => (int)$per,
    'page_last' => (int)$p,
    'last_exp'  => (int)!!$last_exp,
    'last_ok'   => (int)!!$last_ok,
  ], $base_admin) );
};

$admin_js_data['lastPagerUrl'] = esc_url_raw(add_query_arg([
    'page'      => 'pt-simple-backup',
    'tab'       => 'last',
    'per_last'  => $per_last,
    'page_last' => '__PAGE__',
    'last_exp'  => (int)!!$last_exp,
    'last_ok'   => (int)!!$last_ok,
], $base_admin));


  ?>

  <h2 style="margin-top:8px">Últimas execuções</h2>

  <?php if (!$rows_last): ?>
    <p><em>Nenhum backup concluído encontrado no Drive.</em></p>
  <?php else: ?>

    <!-- Toolbar: filtros (esq) + Exibindo (dir) -->
<div class="ptsb-toolbar" style="display:inline-flex;gap:12px;flex-wrap:wrap;align-items:center;margin:8px 0 10px">
  <!-- Checkboxes -->
  <form method="get" id="ptsb-last-filter-form" class="ptsb-list-controls" style="margin:0">
    <input type="hidden" name="page" value="pt-simple-backup">
    <input type="hidden" name="tab"  value="last">
    <input type="hidden" name="per_last"  value="<?php echo (int)$per_last; ?>">
    <input type="hidden" name="page_last" value="1">
    <label style="display:inline-flex;align-items:center;gap:6px">
      <input type="checkbox" name="last_exp" value="1" <?php checked($last_exp); ?>>
      <span>Mostrar vencidos</span>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px">
      <input type="checkbox" name="last_ok" value="1" <?php checked($last_ok); ?>>
      <span>Mostrar em dia</span>
    </label>
  </form>

  <!-- “Exibindo …” alinhado à direita -->
  <form method="get" id="ptsb-last-per-form"
        class="ptsb-list-controls"
        style="display:flex;align-items:center;gap:6px;margin:0;margin-left:auto">
    <input type="hidden" name="page" value="pt-simple-backup">
    <input type="hidden" name="tab"  value="last">
    <input type="hidden" name="page_last" value="1">
    <!-- PRESERVA OS FILTROS ATUAIS -->
    <input type="hidden" name="last_exp" value="<?php echo (int)$last_exp; ?>">
    <input type="hidden" name="last_ok"  value="<?php echo (int)$last_ok; ?>">

    <span>Exibindo</span>
    <input type="number" name="per_last" min="1" max="500"
           value="<?php echo (int)$per_last; ?>" style="width:auto">
    <span>de <?php echo (int)$total_last; ?> execuções — página
      <?php echo (int)$page_last; ?> de <?php echo (int)$total_pages_l; ?></span>
  </form>
</div>
<table class="widefat striped">
      <thead>
        <tr>
          <th>Data/Hora</th>
          <th>Arquivo</th>
          <th>Rotina</th>
          <th>Backup</th>
          <th>Retenção</th>
          <th>Tamanho</th>
        </tr>
      </thead>
      <tbody>
      
<?php foreach ($rows_last as $r):
  $time = $r['time']; $file = $r['file']; $size = (int)($r['size'] ?? 0);
  $manifest     = ptsb_manifest_read($file);
  $rotina_label = ptsb_run_kind_label($manifest, $file);
  $letters      = [];
  if (!empty($manifest['parts'])) $letters = ptsb_parts_to_letters($manifest['parts']);
  if (!$letters) $letters = ['D','P','T','W','S','M','O'];
  $is_kept  = !empty($keepers[$file]);
  $keepDays = ptsb_manifest_keep_days($manifest, (int)$set['keep_days']);

  // >>> NOVO: detecção de vencido (somente se não for "sempre manter")
  $ri = null; $is_expired = false;
  if (!$is_kept && is_int($keepDays) && $keepDays > 0) {
      $ri = ptsb_retention_calc($time, $keepDays);
      $is_expired = ($ri['x'] >= $ri['y']);
  }
    $tr_class = ($is_expired ? ' class="ptsb-expired"' : '');
?>
<tr>
    <tr<?php echo $tr_class; ?>>
  <td><?php echo esc_html( ptsb_fmt_local_dt($time) ); ?></td>
  <td><?php echo esc_html($file); ?></td>
  <td><?php echo esc_html($rotina_label); ?></td>
  <td>
    <?php foreach ($letters as $L): $meta = ptsb_letter_meta($L); ?>
      <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
        <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
      </span>
    <?php endforeach; ?>
  </td>
  <td>
    <?php if ($is_kept): ?>
      <span class="ptsb-ret sempre" title="Sempre manter">sempre</span>
    <?php elseif (is_int($keepDays) && $keepDays > 0):
      $ri = $ri ?: ptsb_retention_calc($time, $keepDays); ?>
      <span class="ptsb-ret" title="<?php echo esc_attr('Dia '.$ri['x'].' de '.$ri['y']); ?>">
        <?php echo (int)$ri['x'].'/'.(int)$ri['y']; ?>
      </span>
      <?php if ($is_expired): ?>
        <!-- >>> NOVO: selo VENCIDO nesta aba -->
        <span class="ptsb-tag vencido">VENCIDO</span>
      <?php endif; ?>
    <?php else: ?>
      —
    <?php endif; ?>
  </td>
  <td><?php echo esc_html( ptsb_hsize($size) ); ?></td>
</tr>
<?php endforeach; ?>

      
      </tbody>
    </table>

    <?php if ($total_pages_l > 1): ?>
      <nav class="ptsb-pager" aria-label="Paginação das últimas execuções">
        <a class="btn <?php echo $page_last<=1?'is-disabled':''; ?>"
           href="<?php echo $page_last>1 ? $make_url_l(1, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last<=1?'true':'false'; ?>"
           title="Primeira página">
          <span class="dashicons dashicons-controls-skipback"></span>
        </a>

        <a class="btn <?php echo $page_last<=1?'is-disabled':''; ?>"
           href="<?php echo $page_last>1 ? $make_url_l($page_last-1, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last<=1?'true':'false'; ?>"
           title="Página anterior">
          <span class="dashicons dashicons-arrow-left-alt2"></span>
        </a>

        <span class="status">
          <input id="ptsb-last-pager-input" class="current" type="number"
                 min="1" max="<?php echo (int)$total_pages_l; ?>" value="<?php echo (int)$page_last; ?>">
          <span class="sep">de</span>
          <span class="total"><?php echo (int)$total_pages_l; ?></span>
        </span>

        <a class="btn <?php echo $page_last>=$total_pages_l?'is-disabled':''; ?>"
           href="<?php echo $page_last<$total_pages_l ? $make_url_l($page_last+1, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last>=$total_pages_l?'true':'false'; ?>"
           title="Próxima página">
          <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>

        <a class="btn <?php echo $page_last>=$total_pages_l?'is-disabled':''; ?>"
           href="<?php echo $page_last<$total_pages_l ? $make_url_l($total_pages_l, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last>=$total_pages_l?'true':'false'; ?>"
           title="Última página">
          <span class="dashicons dashicons-controls-skipforward"></span>
        </a>
      </nav>
<?php endif; ?>

  <?php endif; // rows_last ?>


<?php elseif ($tab === 'settings'): ?>

  <!-- ===== ABA: CONFIGURAÇÕES ===== -->
  <h2 style="margin-top:8px">Log</h2>



  <?php $init_log = ptsb_tail_log_raw($cfg['log'], 50); ?>

  <p style="opacity:.7;margin:.3em 0 1em">
        Status: <?php echo esc_html(implode(' | ', $diag)); ?>
      </p>

  <div style="display:flex;align-items:center;gap:8px;margin:10px 0 6px">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
          onsubmit="return confirm('Limpar todo o log (incluindo rotações)?');" style="margin-left:auto">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="clear_log"/>
      <button class="button">Limpar log</button>
    </form>
  </div>

  <pre id="ptsb-log" style="max-height:420px;overflow:auto;padding:10px;background:#111;border:1px solid #444;border-radius:4px;"><?php 
      echo esc_html($init_log ?: '(sem linhas)'); 
  ?></pre>
  <p><small>Mostrando as últimas 50 linhas. A rotação cria <code>backup-wp.log.1</code>, <code>.2</code>… até <?php echo (int)$cfg['log_keep']; ?>.</small></p>
<?php endif; // fim roteamento abas ?>
    </div>
    <?php
    settings_errors('ptsb');

    if (!empty($admin_js_data)) {
        wp_localize_script('ptsb-admin', 'PTSB_DATA', $admin_js_data);
    }

}

