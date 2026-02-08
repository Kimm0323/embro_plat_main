<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design Customization Editor</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .editor-layout {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 24px;
            margin-bottom: 40px;
        }
        .editor-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            border: 1px solid #e2e8f0;
        }
        .editor-toolbar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .editor-canvas-wrapper {
            position: relative;
            background: #f8fafc;
            border-radius: 14px;
            padding: 16px;
        }
        .editor-canvas-wrapper canvas {
            width: 100%;
            height: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-pill.safe {
            background: #dcfce7;
            color: #166534;
        }
        .status-pill.warn {
            background: #fee2e2;
            color: #991b1b;
        }
        .layer-list {
            max-height: 220px;
            overflow-y: auto;
            margin-top: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 8px;
            background: #f8fafc;
        }
        .layer-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            border-radius: 10px;
            cursor: pointer;
        }
        .layer-item.active {
            background: #e0f2fe;
            color: #0f172a;
        }
        .version-list {
            display: grid;
            gap: 8px;
            margin-top: 12px;
        }
        .version-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .help-grid {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }
        .help-item {
            background: #f8fafc;
            padding: 12px;
            border-radius: 12px;
            border: 1px dashed #cbd5f5;
        }
        .slider-row {
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 12px;
        }
        @media (max-width: 980px) {
            .editor-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user"></i> Client Portal
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="design_editor.php" class="dropdown-item active"><i class="fas fa-pencil-ruler"></i> Design Editor</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if ($unread_notifications > 0): ?>
                        <span class="badge badge-danger"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Design Customization Editor</h2>
            <p class="text-muted">Build embroidery-ready layouts with safe-area validation, color control, and version history.</p>
        </div>

        <div class="editor-layout">
            <div class="editor-panel">
                <div class="editor-toolbar">
                    <div class="form-group">
                        <label>Hoop Size Preset</label>
                        <select id="hoopPreset" class="form-control">
                            <option value="4x4">4" x 4" (Small)</option>
                            <option value="5x7">5" x 7" (Medium)</option>
                            <option value="6x10">6" x 10" (Large)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Thread Color</label>
                        <input type="color" id="threadColor" class="form-control" value="#1d4ed8">
                    </div>
                    <div class="form-group">
                        <label>Safe Area Overlay</label>
                        <select id="safeAreaToggle" class="form-control">
                            <option value="on" selected>Show Safe Area</option>
                            <option value="off">Hide Safe Area</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Upload Logo</label>
                        <input type="file" id="imageUpload" class="form-control" accept=".png,.jpg,.jpeg,.svg">
                    </div>
                </div>

                <div class="editor-canvas-wrapper">
                    <canvas id="designCanvas" width="900" height="620"></canvas>
                </div>

                <div class="d-flex justify-between align-center mt-3">
                    <div id="safeStatus" class="status-pill safe"><i class="fas fa-shield-check"></i> Safe area ready</div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline" id="undoBtn"><i class="fas fa-rotate-left"></i> Undo</button>
                        <button class="btn btn-outline" id="redoBtn"><i class="fas fa-rotate-right"></i> Redo</button>
                    </div>
                </div>
            </div>

            <div class="editor-panel">
                <h4>Element Controls</h4>
                <div class="form-group">
                    <label>Add Text</label>
                    <div class="d-flex gap-2">
                        <input type="text" id="textInput" class="form-control" placeholder="Enter text">
                        <button class="btn btn-primary" id="addTextBtn"><i class="fas fa-plus"></i></button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Selected Element</label>
                    <div class="slider-row">
                        <input type="range" id="scaleSlider" min="0.3" max="2" step="0.1" value="1">
                        <span id="scaleValue">100%</span>
                    </div>
                    <div class="slider-row mt-2">
                        <input type="range" id="rotationSlider" min="-180" max="180" step="5" value="0">
                        <span id="rotationValue">0°</span>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button class="btn btn-outline" id="bringForwardBtn"><i class="fas fa-layer-group"></i> Forward</button>
                        <button class="btn btn-outline" id="sendBackwardBtn"><i class="fas fa-layer-group"></i> Back</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Layer Stack</label>
                    <div id="layerList" class="layer-list"></div>
                </div>

                <div class="form-group">
                    <label>Versioning</label>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" id="saveVersionBtn"><i class="fas fa-save"></i> Save Version</button>
                        <button class="btn btn-outline" id="exportJsonBtn"><i class="fas fa-file-code"></i> Design JSON</button>
                        <button class="btn btn-outline" id="exportPngBtn"><i class="fas fa-image"></i> PNG Proof</button>
                    </div>
                    <div class="version-list" id="versionList"></div>
                </div>

                <div class="help-grid">
                    <div class="help-item"><strong>Auto-save:</strong> Versions are stored locally every 45 seconds and whenever you manually save.</div>
                    <div class="help-item"><strong>Boundary validation:</strong> The editor flags any elements outside the safe embroidery zone.</div>
                    <div class="help-item"><strong>Supported assets:</strong> PNG, JPG, and SVG logos plus editable text layers.</div>
                </div>
            </div>
        </div>
    </div>

<script>
const canvas = document.getElementById('designCanvas');
const ctx = canvas.getContext('2d');
const hoopPreset = document.getElementById('hoopPreset');
const threadColor = document.getElementById('threadColor');
const safeAreaToggle = document.getElementById('safeAreaToggle');
const imageUpload = document.getElementById('imageUpload');
const textInput = document.getElementById('textInput');
const addTextBtn = document.getElementById('addTextBtn');
const scaleSlider = document.getElementById('scaleSlider');
const scaleValue = document.getElementById('scaleValue');
const rotationSlider = document.getElementById('rotationSlider');
const rotationValue = document.getElementById('rotationValue');
const layerList = document.getElementById('layerList');
const saveVersionBtn = document.getElementById('saveVersionBtn');
const exportJsonBtn = document.getElementById('exportJsonBtn');
const exportPngBtn = document.getElementById('exportPngBtn');
const versionList = document.getElementById('versionList');
const undoBtn = document.getElementById('undoBtn');
const redoBtn = document.getElementById('redoBtn');
const safeStatus = document.getElementById('safeStatus');

const presets = {
    '4x4': { width: 4, height: 4 },
    '5x7': { width: 5, height: 7 },
    '6x10': { width: 6, height: 10 }
};

const state = {
    elements: [],
    selectedId: null,
    hoopPreset: hoopPreset.value,
    threadColor: threadColor.value,
    showSafeArea: true,
    versions: [],
    versionCounter: 1,
    history: [],
    future: []
};

const storageKey = 'embroider_design_editor';

function loadState() {
    const saved = localStorage.getItem(storageKey);
    if (!saved) {
        pushHistory();
        render();
        return;
    }
    const parsed = JSON.parse(saved);
    Object.assign(state, parsed);
    state.elements = state.elements.map(element => ({ ...element }));
    state.history = [];
    state.future = [];
    hoopPreset.value = state.hoopPreset || '4x4';
    threadColor.value = state.threadColor || '#1d4ed8';
    safeAreaToggle.value = state.showSafeArea ? 'on' : 'off';
    rebuildImages();
    pushHistory();
    render();
    renderVersions();
}

function saveState() {
    localStorage.setItem(storageKey, JSON.stringify({
        elements: state.elements,
        hoopPreset: state.hoopPreset,
        threadColor: state.threadColor,
        showSafeArea: state.showSafeArea,
        versions: state.versions,
        versionCounter: state.versionCounter
    }));
}

function pushHistory() {
    state.history.push(JSON.stringify({
        elements: state.elements,
        hoopPreset: state.hoopPreset,
        threadColor: state.threadColor,
        showSafeArea: state.showSafeArea,
        selectedId: state.selectedId
    }));
    if (state.history.length > 30) {
        state.history.shift();
    }
    state.future = [];
}

function restoreFromHistory(entry) {
    const restored = JSON.parse(entry);
    state.elements = restored.elements;
    state.hoopPreset = restored.hoopPreset;
    state.threadColor = restored.threadColor;
    state.showSafeArea = restored.showSafeArea;
    state.selectedId = restored.selectedId;
    hoopPreset.value = state.hoopPreset;
    threadColor.value = state.threadColor;
    safeAreaToggle.value = state.showSafeArea ? 'on' : 'off';
    rebuildImages();
    render();
    saveState();
}

function rebuildImages() {
    state.elements.forEach(element => {
        if (element.type === 'image' && element.src) {
            const img = new Image();
            img.src = element.src;
            element.image = img;
        }
    });
}

function getHoopDimensions() {
    const preset = presets[state.hoopPreset];
    const aspect = preset.width / preset.height;
    const baseWidth = canvas.width * 0.8;
    const width = baseWidth;
    const height = baseWidth / aspect;
    return { width, height };
}

function drawCanvas() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const hoop = getHoopDimensions();
    const hoopX = (canvas.width - hoop.width) / 2;
    const hoopY = (canvas.height - hoop.height) / 2;

    ctx.save();
    ctx.strokeStyle = '#94a3b8';
    ctx.lineWidth = 3;
    ctx.strokeRect(hoopX, hoopY, hoop.width, hoop.height);
    ctx.restore();

    if (state.showSafeArea) {
        ctx.save();
        ctx.strokeStyle = '#38bdf8';
        ctx.setLineDash([10, 8]);
        ctx.lineWidth = 2;
        ctx.strokeRect(hoopX + 24, hoopY + 24, hoop.width - 48, hoop.height - 48);
        ctx.restore();
    }

    state.elements.forEach(element => {
        ctx.save();
        ctx.translate(element.x, element.y);
        ctx.rotate(element.rotation * Math.PI / 180);
        ctx.scale(element.scale, element.scale);

        if (element.type === 'text') {
            ctx.fillStyle = element.color;
            ctx.font = `bold ${element.fontSize}px 'Inter', sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(element.text, 0, 0);
        }

        if (element.type === 'image' && element.image) {
            const drawWidth = element.width;
            const drawHeight = element.height;
            ctx.drawImage(element.image, -drawWidth / 2, -drawHeight / 2, drawWidth, drawHeight);
        }

        if (element.id === state.selectedId) {
            ctx.strokeStyle = '#2563eb';
            ctx.lineWidth = 2;
            const bounds = getElementBounds(element);
            ctx.strokeRect(bounds.x, bounds.y, bounds.width, bounds.height);
        }

        ctx.restore();
    });
}

function getElementBounds(element) {
    if (element.type === 'text') {
        const width = element.text.length * element.fontSize * 0.6;
        const height = element.fontSize * 1.2;
        return { x: -width / 2, y: -height / 2, width, height };
    }
    return { x: -element.width / 2, y: -element.height / 2, width: element.width, height: element.height };
}

function validateSafeArea() {
    const hoop = getHoopDimensions();
    const hoopX = (canvas.width - hoop.width) / 2;
    const hoopY = (canvas.height - hoop.height) / 2;
    const safe = {
        x: hoopX + 24,
        y: hoopY + 24,
        width: hoop.width - 48,
        height: hoop.height - 48
    };

    const outOfBounds = state.elements.some(element => {
        const bounds = getElementBounds(element);
        const scaledWidth = bounds.width * element.scale;
        const scaledHeight = bounds.height * element.scale;
        const left = element.x + bounds.x * element.scale;
        const top = element.y + bounds.y * element.scale;
        const right = left + scaledWidth;
        const bottom = top + scaledHeight;
        return left < safe.x || top < safe.y || right > safe.x + safe.width || bottom > safe.y + safe.height;
    });

    if (outOfBounds) {
        safeStatus.className = 'status-pill warn';
        safeStatus.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Outside safe area';
    } else {
        safeStatus.className = 'status-pill safe';
        safeStatus.innerHTML = '<i class="fas fa-shield-check"></i> Safe area ready';
    }
}

function renderLayerList() {
    layerList.innerHTML = '';
    state.elements.slice().reverse().forEach(element => {
        const item = document.createElement('div');
        item.className = 'layer-item' + (element.id === state.selectedId ? ' active' : '');
        item.textContent = `${element.type === 'text' ? 'Text' : 'Image'}: ${element.label}`;
        item.onclick = () => {
            state.selectedId = element.id;
            updateControlValues();
            render();
        };
        layerList.appendChild(item);
    });
}

function renderVersions() {
    versionList.innerHTML = '';
    state.versions.slice().reverse().forEach(version => {
        const card = document.createElement('div');
        card.className = 'version-card';
        card.innerHTML = `<span>${version.name}</span><button class="btn btn-outline btn-sm">Load</button>`;
        card.querySelector('button').onclick = () => {
            state.elements = version.data.elements;
            state.hoopPreset = version.data.hoopPreset;
            state.threadColor = version.data.threadColor;
            state.showSafeArea = version.data.showSafeArea;
            state.selectedId = null;
            hoopPreset.value = state.hoopPreset;
            threadColor.value = state.threadColor;
            safeAreaToggle.value = state.showSafeArea ? 'on' : 'off';
            rebuildImages();
            pushHistory();
            render();
            saveState();
        };
        versionList.appendChild(card);
    });
}

function render() {
    drawCanvas();
    renderLayerList();
    validateSafeArea();
    updateControlValues();
}

function updateControlValues() {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected) {
        scaleSlider.value = 1;
        rotationSlider.value = 0;
        scaleValue.textContent = '100%';
        rotationValue.textContent = '0°';
        return;
    }
    scaleSlider.value = selected.scale;
    rotationSlider.value = selected.rotation;
    scaleValue.textContent = `${Math.round(selected.scale * 100)}%`;
    rotationValue.textContent = `${selected.rotation}°`;
}

function addText() {
    if (!textInput.value.trim()) return;
    const element = {
        id: `text-${Date.now()}`,
        type: 'text',
        text: textInput.value.trim(),
        label: textInput.value.trim().slice(0, 12),
        x: canvas.width / 2,
        y: canvas.height / 2,
        scale: 1,
        rotation: 0,
        fontSize: 48,
        color: state.threadColor
    };
    state.elements.push(element);
    state.selectedId = element.id;
    textInput.value = '';
    pushHistory();
    render();
    saveState();
}

function addImage(file) {
    const reader = new FileReader();
    reader.onload = event => {
        const img = new Image();
        img.onload = () => {
            const maxWidth = 240;
            const scale = maxWidth / img.width;
            const element = {
                id: `image-${Date.now()}`,
                type: 'image',
                label: file.name,
                src: event.target.result,
                image: img,
                x: canvas.width / 2,
                y: canvas.height / 2,
                width: img.width * scale,
                height: img.height * scale,
                scale: 1,
                rotation: 0
            };
            state.elements.push(element);
            state.selectedId = element.id;
            pushHistory();
            render();
            saveState();
        };
        img.src = event.target.result;
    };
    reader.readAsDataURL(file);
}

let isDragging = false;
let dragOffset = { x: 0, y: 0 };

canvas.addEventListener('mousedown', event => {
    const rect = canvas.getBoundingClientRect();
    const x = (event.clientX - rect.left) * (canvas.width / rect.width);
    const y = (event.clientY - rect.top) * (canvas.height / rect.height);
    const selected = [...state.elements].reverse().find(element => {
        const bounds = getElementBounds(element);
        const width = bounds.width * element.scale;
        const height = bounds.height * element.scale;
        return x >= element.x - width / 2 && x <= element.x + width / 2
            && y >= element.y - height / 2 && y <= element.y + height / 2;
    });
    if (selected) {
        state.selectedId = selected.id;
        isDragging = true;
        dragOffset = { x: x - selected.x, y: y - selected.y };
        render();
    }
});

canvas.addEventListener('mousemove', event => {
    if (!isDragging) return;
    const rect = canvas.getBoundingClientRect();
    const x = (event.clientX - rect.left) * (canvas.width / rect.width);
    const y = (event.clientY - rect.top) * (canvas.height / rect.height);
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected) return;
    selected.x = x - dragOffset.x;
    selected.y = y - dragOffset.y;
    render();
});

canvas.addEventListener('mouseup', () => {
    if (isDragging) {
        pushHistory();
        saveState();
    }
    isDragging = false;
});

canvas.addEventListener('mouseleave', () => {
    isDragging = false;
});

addTextBtn.addEventListener('click', addText);
textInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
        event.preventDefault();
        addText();
    }
});

imageUpload.addEventListener('change', event => {
    const file = event.target.files[0];
    if (file) {
        addImage(file);
        event.target.value = '';
    }
});

hoopPreset.addEventListener('change', () => {
    state.hoopPreset = hoopPreset.value;
    pushHistory();
    render();
    saveState();
});

threadColor.addEventListener('change', () => {
    state.threadColor = threadColor.value;
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (selected && selected.type === 'text') {
        selected.color = state.threadColor;
    }
    pushHistory();
    render();
    saveState();
});

safeAreaToggle.addEventListener('change', () => {
    state.showSafeArea = safeAreaToggle.value === 'on';
    pushHistory();
    render();
    saveState();
});

scaleSlider.addEventListener('input', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected) return;
    selected.scale = parseFloat(scaleSlider.value);
    scaleValue.textContent = `${Math.round(selected.scale * 100)}%`;
    render();
});

scaleSlider.addEventListener('change', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected) return;
    pushHistory();
    saveState();
});

rotationSlider.addEventListener('input', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected) return;
    selected.rotation = parseInt(rotationSlider.value, 10);
    rotationValue.textContent = `${selected.rotation}°`;
    render();
});

rotationSlider.addEventListener('change', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected) return;
    pushHistory();
    saveState();
});

function moveLayer(direction) {
    const index = state.elements.findIndex(element => element.id === state.selectedId);
    if (index === -1) return;
    const newIndex = direction === 'forward' ? index + 1 : index - 1;
    if (newIndex < 0 || newIndex >= state.elements.length) return;
    const [element] = state.elements.splice(index, 1);
    state.elements.splice(newIndex, 0, element);
    pushHistory();
    render();
    saveState();
}

document.getElementById('bringForwardBtn').addEventListener('click', () => moveLayer('forward'));
document.getElementById('sendBackwardBtn').addEventListener('click', () => moveLayer('back'));

undoBtn.addEventListener('click', () => {
    if (state.history.length <= 1) return;
    const current = state.history.pop();
    state.future.push(current);
    restoreFromHistory(state.history[state.history.length - 1]);
});

redoBtn.addEventListener('click', () => {
    if (!state.future.length) return;
    const next = state.future.pop();
    state.history.push(next);
    restoreFromHistory(next);
});

saveVersionBtn.addEventListener('click', () => {
    const name = `v${state.versionCounter}`;
    state.versionCounter += 1;
    state.versions.push({
        name,
        savedAt: new Date().toISOString(),
        data: {
            elements: state.elements,
            hoopPreset: state.hoopPreset,
            threadColor: state.threadColor,
            showSafeArea: state.showSafeArea
        }
    });
    renderVersions();
    saveState();
});

exportJsonBtn.addEventListener('click', () => {
    const payload = {
        hoopPreset: state.hoopPreset,
        threadColor: state.threadColor,
        showSafeArea: state.showSafeArea,
        elements: state.elements
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'design.json';
    link.click();
    URL.revokeObjectURL(url);
});

exportPngBtn.addEventListener('click', () => {
    const link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = 'design-proof.png';
    link.click();
});

setInterval(() => {
    const name = `Auto-save v${state.versionCounter}`;
    state.versionCounter += 1;
    state.versions.push({
        name,
        savedAt: new Date().toISOString(),
        data: {
            elements: state.elements,
            hoopPreset: state.hoopPreset,
            threadColor: state.threadColor,
            showSafeArea: state.showSafeArea
        }
    });
    if (state.versions.length > 12) {
        state.versions.shift();
    }
    renderVersions();
    saveState();
}, 45000);

loadState();
</script>
</body>
</html>
