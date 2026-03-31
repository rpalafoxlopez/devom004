<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Convertidor TXT → Excel | Pagos</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f4f8;
            color: #2d3748;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 32px 16px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.1);
            width: 100%;
            max-width: 780px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #111111, #000000);
            color: #fff;
            padding: 28px 32px;
        }
        .card-header h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 4px; }
        .card-header p  { font-size: .9rem; opacity: .85; }

        .card-body { padding: 32px; }

        /* Alertas */
        .alert {
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: .9rem;
        }
        .alert-success { background:#d4edda; border:1px solid #c3e6cb; color:#155724; }
        .alert-error   { background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; }

        /* Zona de drop */
        .drop-zone {
            border: 2px dashed #90cdf4;
            border-radius: 10px;
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            transition: background .2s, border-color .2s;
            background: #ebf8ff;
            position: relative;
        }
        .drop-zone:hover,
        .drop-zone.dragover { background: #bee3f8; border-color: #2e86c1; }

        .drop-icon { font-size: 2.5rem; margin-bottom: 10px; }
        .drop-zone h3 { font-size: 1rem; color: #2c5282; margin-bottom: 4px; }
        .drop-zone p  { font-size: .82rem; color: #4a90d9; }

        /* Lista de archivos */
        #lista-archivos {
            margin-top: 16px;
            display: none;
        }
        #lista-archivos h4 {
            font-size: .85rem;
            font-weight: 700;
            color: #4a5568;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .file-list { list-style: none; display: flex; flex-direction: column; gap: 6px; }

        .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: .875rem;
        }
        .file-item .file-icon { font-size: 1.1rem; flex-shrink: 0; }
        .file-item .file-name { flex: 1; font-weight: 600; color: #2d3748; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-item .file-size { color: #718096; font-size: .78rem; white-space: nowrap; }
        .file-item .remove-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #e53e3e;
            font-size: 1rem;
            padding: 2px 6px;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .file-item .remove-btn:hover { background: #fff5f5; }

        /* Opciones */
        .section-title {
            font-size: .8rem;
            font-weight: 700;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 12px;
            margin-top: 24px;
        }

        .options-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .option-card {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 16px;
            cursor: pointer;
            transition: border-color .2s, background .2s;
        }
        .option-card.selected { border-color: #2e86c1; background: #ebf8ff; }

        .option-card input[type="radio"] { display: none; }

        .option-card .opt-title { font-weight: 700; font-size: .9rem; color: #2d3748; margin-bottom: 3px; }
        .option-card .opt-desc  { font-size: .78rem; color: #718096; }

        /* Botones */
        .btn-group { margin-top: 28px; }

        .btn {
            width: 100%;
            padding: 13px 20px;
            border: none;
            border-radius: 8px;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform .15s, box-shadow .15s, opacity .15s;
        }
        .btn:hover  { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .btn:active { transform: translateY(0); }
        .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }

        .btn-primary { background: #3f3f3f; color: #ffffff; }

        /* Spinner */
        .spinner { display: none; text-align: center; padding: 16px 0; }
        .spinner .spinner-ring {
            display: inline-block;
            width: 36px; height: 36px;
            border: 4px solid #bee3f8;
            border-top-color: #2e86c1;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <h1>Convertidor TXT → Excel</h1>
        <p>Archivos de nómina / pagos en formato de posiciones fijas &mdash; selecciona uno o varios archivos</p>
    </div>

    <div class="card-body">

        {{-- Mensajes de éxito --}}
        @if(session('success'))
            <div class="alert alert-success">
                <strong>✅ {{ session('success') }}</strong>
            </div>
        @endif

        {{-- Mensajes de error --}}
        @if($errors->any())
            <div class="alert alert-error">
                <strong>❌ Error:</strong>
                <ul style="margin-top:6px; padding-left:18px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Formulario principal --}}
        <form id="form-descargar" method="POST" action="{{ route('pagos.descargar') }}" enctype="multipart/form-data">
            @csrf

            {{-- Input file OCULTO pero que se llenará con los archivos seleccionados --}}
            <input type="file" name="archivos[]" id="archivos-input" accept=".txt,.TXT" multiple style="display: none;">

            {{-- Zona de arrastrar/seleccionar archivos --}}
            <div class="drop-zone" id="drop-zone">
                <div class="drop-icon">📂</div>
                <h3>Arrastra tus archivos aquí o haz clic para seleccionar</h3>
                <p>Formato .TXT · Posiciones fijas · Múltiples archivos permitidos</p>
            </div>

            <div id="lista-archivos">
                <h4 id="contador-archivos">0 archivos seleccionados</h4>
                <ul class="file-list" id="file-list"></ul>
            </div>

            {{-- Opciones de generación --}}
            <p class="section-title">Modo de hojas en el Excel</p>
            <div class="options-grid">
                {{--
                    <--label class="option-card selected" id="opt-unica">
                        <--input type="radio" name="modo_hoja" value="unica" checked>
                        <--div class="opt-title">📋 Una sola hoja</-div>
                        <--div class="opt-desc">Todos los registros combinados en una hoja "Pagos"</-div>
                    </-label>
                --}}
                <label class="option-card selected" id="opt-separada">
                    <input type="radio" name="modo_hoja" value="separada" checked>
                    <div class="opt-title">📑 Hoja por archivo</div>
                    <div class="opt-desc">Cada archivo TXT genera su propia hoja en el Excel</div>
                </label>
            </div>

            {{-- Spinner de carga --}}
            <div class="spinner" id="spinner">
                <div class="spinner-ring"></div>
                <p style="margin-top:10px;font-size:.85rem;color:#4a5568;">Procesando archivos…</p>
            </div>

            {{-- Botón de descarga --}}
            <div class="btn-group">
                <button type="submit" class="btn btn-primary" id="btn-descargar" disabled>⬇️ Descargar Excel</button>
            </div>
        </form>

    </div>
</div>

<script>
(function () {
    // Elementos DOM
    const dropZone      = document.getElementById('drop-zone');
    const fileInput     = document.getElementById('archivos-input'); // Input file del formulario
    const listaWrapper  = document.getElementById('lista-archivos');
    const fileList      = document.getElementById('file-list');
    const contador      = document.getElementById('contador-archivos');
    const btnDescargar  = document.getElementById('btn-descargar');
    const spinner       = document.getElementById('spinner');
    const form          = document.getElementById('form-descargar');

    // Almacenar archivos seleccionados
    let archivosSeleccionados = [];

    // ── Click en drop zone para abrir selector de archivos ─────────────────────
    dropZone.addEventListener('click', () => {
        // Crear input temporal para seleccionar archivos
        const tempInput = document.createElement('input');
        tempInput.type = 'file';
        tempInput.multiple = true;
        tempInput.accept = '.txt,.TXT';

        tempInput.addEventListener('change', (e) => {
            agregarArchivos(Array.from(e.target.files));
        });

        tempInput.click();
    });

    // ── Drag & Drop ──────────────────────────────────────────────────────────
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');

        const files = Array.from(e.dataTransfer.files).filter(f =>
            f.name.toLowerCase().endsWith('.txt')
        );
        agregarArchivos(files);
    });

    // ── Opciones de hoja (estilo visual) ─────────────────────────────────────
    const radioUnica = document.querySelector('input[value="unica"]');
    const radioSeparada = document.querySelector('input[value="separada"]');
    const optUnica = document.getElementById('opt-unica');
    const optSeparada = document.getElementById('opt-separada');

    function updateRadioStyles() {
        if (radioUnica.checked) {
            optUnica.classList.add('selected');
            optSeparada.classList.remove('selected');
        } else {
            optUnica.classList.remove('selected');
            optSeparada.classList.add('selected');
        }
    }

    radioUnica.addEventListener('change', updateRadioStyles);
    radioSeparada.addEventListener('change', updateRadioStyles);

    // ── Gestión de archivos seleccionados ────────────────────────────────────
    function agregarArchivos(nuevos) {
        nuevos.forEach(f => {
            // Verificar si ya existe (por nombre y tamaño)
            const existe = archivosSeleccionados.some(e =>
                e.name === f.name && e.size === f.size
            );
            if (!existe) {
                archivosSeleccionados.push(f);
            }
        });
        renderLista();
        actualizarInputFormulario();
    }

    function eliminarArchivo(index) {
        archivosSeleccionados.splice(index, 1);
        renderLista();
        actualizarInputFormulario();
    }

    function renderLista() {
        fileList.innerHTML = '';

        if (archivosSeleccionados.length === 0) {
            listaWrapper.style.display = 'none';
            contador.textContent = '0 archivos seleccionados';
            btnDescargar.disabled = true;
            return;
        }

        archivosSeleccionados.forEach((f, idx) => {
            const li = document.createElement('li');
            li.className = 'file-item';
            li.innerHTML = `
                <span class="file-icon">📄</span>
                <span class="file-name" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</span>
                <span class="file-size">${formatSize(f.size)}</span>
                <button type="button" class="remove-btn" data-idx="${idx}" title="Quitar">✕</button>
            `;
            fileList.appendChild(li);
        });

        // Event listeners para botones eliminar
        document.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.dataset.idx);
                eliminarArchivo(idx);
            });
        });

        const n = archivosSeleccionados.length;
        listaWrapper.style.display = 'block';
        contador.textContent = n === 1 ? '1 archivo seleccionado' : `${n} archivos seleccionados`;
        btnDescargar.disabled = false;
    }

    // ⭐ FUNCIÓN CRÍTICA: Actualizar el input file del formulario con los archivos seleccionados
    function actualizarInputFormulario() {
        // Crear un nuevo DataTransfer y agregar todos los archivos seleccionados
        const dataTransfer = new DataTransfer();

        archivosSeleccionados.forEach(archivo => {
            dataTransfer.items.add(archivo);
        });

        // Asignar los archivos al input del formulario
        fileInput.files = dataTransfer.files;

        // Debug: verificar cuántos archivos se asignaron
        console.log(`Archivos asignados al formulario: ${fileInput.files.length}`);
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function escapeHtml(str) {
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;');
    }

    // ── Mostrar spinner al enviar el formulario ──────────────────────────────
    form.addEventListener('submit', (e) => {
        // Verificar que haya archivos seleccionados
        if (archivosSeleccionados.length === 0) {
            e.preventDefault();
            alert('Por favor, selecciona al menos un archivo TXT');
            return;
        }

        // Mostrar spinner y deshabilitar botón
        spinner.style.display = 'block';
        btnDescargar.disabled = true;

        // El formulario se envía normalmente con los archivos en fileInput
    });

    // Inicializar
    renderLista();
})();
</script>
</body>
</html>
