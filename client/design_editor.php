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
            gap: 10px;
            padding: 8px 10px;
            border-radius: 10px;
            cursor: pointer;
        }
         .layer-label {
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .layer-delete-btn {
            border: 0;
            background: transparent;
            color: #dc2626;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
        }
        .layer-delete-btn:hover {
            background: #fee2e2;
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
        .version-actions {
            display: flex;
            gap: 8px;
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
         .editor-inline-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .icon-toggle {
            min-width: 40px;
            padding: 8px 10px;
        }
        .icon-toggle.active {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }
        .nudge-grid {
            display: grid;
            grid-template-columns: repeat(3, 40px);
            gap: 6px;
            justify-content: start;
        }
        .nudge-grid .spacer {
            visibility: hidden;
        }
         .tool-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .tool-grid .btn {
            width: 100%;
        }
        .color-palette {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 8px;
            margin-top: 10px;
        }
        .palette-select-native {
            display: none;
        }
        .palette-dropdown {
            margin-top: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            position: relative;
        }
        .palette-dropdown summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 10px;
            font-size: 0.9rem;
            color: #1e293b;
        }
        .palette-dropdown summary::-webkit-details-marker {
            display: none;
        }
        .palette-dropdown summary::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.75rem;
            color: #64748b;
            transition: transform 0.2s ease;
        }
        .palette-dropdown[open] summary::after {
            transform: rotate(180deg);
        }
        .palette-dropdown-current {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .palette-dropdown-menu {
            margin-top: 0;
            border-top: 1px solid #e2e8f0;
            padding: 10px;
            max-height: 200px;
            overflow-y: auto;
            background: #f8fafc;
        }
        .color-palette-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 6px 8px;
            background: #ffffff;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .color-palette-btn:hover {
            border-color: #64748b;
            transform: translateY(-1px);
        }
        .color-palette-btn.active {
            border-color: #1d4ed8;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        .color-swatch {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 1px solid rgba(15, 23, 42, 0.25);
            flex-shrink: 0;
        }
        .color-palette-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-align: left;
            color: #1e293b;
            font-weight: 500;
        }
         .preview-stage {
            margin-bottom: 14px;
            padding: 14px;
            border-radius: 12px;
            border: 1px solid #dbeafe;
            background: radial-gradient(circle at 15% 10%, #eff6ff, #dbeafe 65%, #bfdbfe);
        }
        .preview-shell {
            perspective: 900px;
            height: 170px;
            display: grid;
            place-items: center;
        }
        .preview-model {
            width: 190px;
            height: 140px;
            border-radius: 20px;
            border: 1px solid rgba(15, 23, 42, 0.22);
            background: linear-gradient(145deg, rgba(255,255,255,0.75), rgba(148, 163, 184, 0.22));
            transform: rotateY(var(--model-rotation, -20deg)) rotateX(8deg);
            box-shadow: 0 20px 35px rgba(15, 23, 42, 0.18);
            transition: transform 0.25s ease;
            position: relative;
            overflow: hidden;
        }
        .preview-model::after {
            content: '';
            position: absolute;
            inset: 20% 18%;
            border: 2px dashed rgba(15, 23, 42, 0.3);
            border-radius: 12px;
        }
        .placement-button-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 8px;
        }
        .placement-btn.active {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }
        .upload-area {
            border: 1px dashed #94a3b8;
            border-radius: 12px;
            padding: 10px;
            background: #f8fafc;
        }
        @media (max-width: 980px) {
            .editor-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>>

    <div class="container">
        <div class="dashboard-header">
            <h2>Design Customization Editor</h2>
            <p class="text-muted">Build embroidery-ready layouts with rotatable preview, placement controls, color tools, and proofing workflow.</p>
        </div>

        <div class="editor-layout">
            <div class="editor-panel">
                <div class="editor-toolbar">
                    <div class="form-group">
                        <label>Canvas Type</label>
                        <select id="canvasType" class="form-control">
                            <option value="tshirt-crew" selected>T-Shirt (Crew Neck)</option>
                            <option value="tshirt-vneck">T-Shirt (V-Neck)</option>
                            <option value="tshirt-polo">T-Shirt (Polo / Collared)</option>
                            <option value="tshirt-tank">T-Shirt (Tank Top / Sleeveless)</option>
                            <option value="tshirt-pocket">T-Shirt (Pocket T-Shirt)</option>
                            <option value="cap-baseball">Cap (Baseball Cap)</option>
                            <option value="cap-bucket">Cap (Bucket Hat)</option>
                            <option value="tote-bag">Tote Bag</option>
                            <option value="plain-canvas">Canvas</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Canvas Color</label>
                         <select id="canvasColor" class="form-control palette-select-native">
                            <option value="#f8fafc" selected>Arctic White</option>
                            <option value="#e2e8f0">Silver Mist</option>
                            <option value="#d6d3d1">Linen Beige</option>
                            <option value="#1f2937">Charcoal Gray</option>
                            <option value="#0f172a">Midnight Navy</option>
                            <option value="#7f1d1d">Maroon Red</option>
                            <option value="#14532d">Forest Green</option>
                            <option value="#111827">Jet Black</option>
                        </select>
                        <details class="palette-dropdown" id="canvasColorDropdown">
                            <summary>
                                <span class="palette-dropdown-current" id="canvasColorCurrentLabel"></span>
                            </summary>
                            <div id="canvasColorPalette" class="color-palette palette-dropdown-menu" aria-label="Canvas color palette"></div>
                        </details>
                    </div>
                    <div class="form-group">
                        <label>Placement Method</label>
                        <select id="placementMethod" class="form-control">
                            <option value="center-chest" selected>Center Chest</option>
                        </select>
                        <div class="placement-button-grid" id="placementButtonGrid">
                            <button type="button" class="btn btn-outline placement-btn" data-placement="center-chest">Front</button>
                            <button type="button" class="btn btn-outline placement-btn" data-placement="back-center">Back</button>
                            <button type="button" class="btn btn-outline placement-btn" data-placement="left-chest">Left</button>
                            <button type="button" class="btn btn-outline placement-btn" data-placement="right-chest">Right</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>T-SHIRT SIZES</label>
                        <select id="hoopPreset" class="form-control">
                            <option value="XS">Extra Small (XS)</option>
                            <option value="S">Small (S)</option>
                            <option value="M" selected>Medium (M)</option>
                            <option value="L">Large (L)</option>
                            <option value="XL">Extra Large (XL)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Thread Color Palette</label>
                         <select id="threadColor" class="form-control palette-select-native">
                            <option value="#111827">Jet Black</option>
                            <option value="#f8fafc">Pearl White</option>
                            <option value="#1d4ed8" selected>Royal Blue</option>
                            <option value="#2563eb">Sky Blue</option>
                            <option value="#dc2626">Crimson Red</option>
                             <option value="#b91c1c">Burgundy Red</option>
                            <option value="#15803d">Emerald Green</option>
                             <option value="#14532d">Forest Green</option>
                            <option value="#f59e0b">Gold Yellow</option>
                            <option value="#f97316">Sunset Orange</option>
                            <option value="#7c3aed">Violet Purple</option>
                            <option value="#db2777">Fuchsia Pink</option>
                        </select>
                        <details class="palette-dropdown" id="threadColorDropdown">
                            <summary>
                                <span class="palette-dropdown-current" id="threadColorCurrentLabel"></span>
                            </summary>
                            <div id="threadColorPalette" class="color-palette palette-dropdown-menu" aria-label="Thread color palette"></div>
                        </details>
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
                        <div class="upload-area">
                            <p class="text-muted" style="margin-bottom:8px;">Drop logo file or browse (PNG, JPG, SVG)</p>
                            <input type="file" id="imageUpload" class="form-control" accept=".png,.jpg,.jpeg,.svg">
                        </div>
                    </div>
                </div>

                <div class="preview-stage">
                    <div class="d-flex justify-between align-center">
                        <strong>3D Item Preview</strong>
                        <small class="text-muted" id="modelRotationValue">-20°</small>
                    </div>
                    <div class="preview-shell mt-2">
                        <div id="previewModel" class="preview-model" aria-label="Rotatable 3D preview model"></div>
                    </div>
                    <div class="slider-row">
                        <input type="range" id="modelRotation" min="-180" max="180" step="5" value="-20">
                        <span>Rotate</span>
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
                       <input type="text" id="textInput" class="form-control" placeholder="Enter text for Center Chest">
                        <button class="btn btn-primary" id="addTextBtn"><i class="fas fa-plus"></i></button>
                    </div>
                     <small id="textPlacementHint" class="text-muted">Text will be placed at Center Chest.</small>
                </div>

                 <div class="form-group">
                    <label>Text Styling</label>
                    <div class="d-flex gap-2">
                        <select id="fontFamily" class="form-control">
                            <option value="Inter">Inter</option>
                            <option value="Arial">Arial</option>
                            <option value="Georgia">Georgia</option>
                            <option value="Courier New">Courier New</option>
                            <option value="Times New Roman">Times New Roman</option>
                        </select>
                       <select id="fontSize" class="form-control">
                            <option value="6">0.1&quot; – 0.25&quot; (2.5 – 6 mm)</option>
                            <option value="9">0.25&quot; – 0.35&quot; (6 – 9 mm)</option>
                            <option value="10">0.25&quot; – 0.4&quot; (6 – 10 mm)</option>
                            <option value="12">0.25&quot; – 0.5&quot; (6 – 12 mm)</option>
                            <option value="25">0.5&quot; – 1&quot; (12 – 25 mm)</option>
                            <option value="50">1&quot; – 2&quot; (25 – 50 mm)</option>
                            <option value="76" selected>1.5&quot; – 3&quot; (38 – 76 mm)</option>
                            <option value="127">2&quot; – 5&quot; (50 – 127 mm)</option>
                            <option value="128">5&quot;+ (127+ mm)</option>
                        </select>
                    </div>
                    <div class="editor-inline-toolbar mt-2">
                        <button class="btn btn-outline icon-toggle" id="boldBtn" title="Bold"><i class="fas fa-bold"></i></button>
                        <button class="btn btn-outline icon-toggle" id="italicBtn" title="Italic"><i class="fas fa-italic"></i></button>
                        <button class="btn btn-outline icon-toggle" id="underlineBtn" title="Underline"><i class="fas fa-underline"></i></button>
                    </div>
                </div>

                <div class="form-group">
                    <div id="logoSliderControls">
                        <div class="slider-row">
                            <input type="range" id="scaleSlider" min="0.3" max="2" step="0.1" value="1">
                            <span id="scaleValue">100%</span>
                        </div>
                        <div class="slider-row mt-2">
                            <input type="range" id="rotationSlider" min="-180" max="180" step="5" value="0">
                            <span id="rotationValue">0°</span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button class="btn btn-outline" id="bringForwardBtn"><i class="fas fa-layer-group"></i> Forward</button>
                        <button class="btn btn-outline" id="sendBackwardBtn"><i class="fas fa-layer-group"></i> Back</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Quick Tools</label>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline" id="duplicateBtn"><i class="fas fa-clone"></i> Duplicate</button>
                        <button class="btn btn-danger" id="deleteSelectedBtn"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                    <div class="nudge-grid mt-2">
                        <span class="spacer">.</span>
                        <button class="btn btn-outline" id="nudgeUpBtn"><i class="fas fa-arrow-up"></i></button>
                        <span class="spacer">.</span>
                        <button class="btn btn-outline" id="nudgeLeftBtn"><i class="fas fa-arrow-left"></i></button>
                        <button class="btn btn-outline" id="nudgeDownBtn"><i class="fas fa-arrow-down"></i></button>
                        <button class="btn btn-outline" id="nudgeRightBtn"><i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Precision Tools</label>
                    <div class="tool-grid">
                        <button class="btn btn-outline" id="centerHorizontalBtn"><i class="fas fa-arrows-left-right"></i> Center X</button>
                        <button class="btn btn-outline" id="centerVerticalBtn"><i class="fas fa-arrows-up-down"></i> Center Y</button>
                        <button class="btn btn-outline" id="flipHorizontalBtn"><i class="fas fa-right-left"></i> Flip X</button>
                        <button class="btn btn-outline" id="flipVerticalBtn"><i class="fas fa-up-down"></i> Flip Y</button>
                        <button class="btn btn-outline" id="resetTransformBtn"><i class="fas fa-rotate"></i> Reset</button>
                        <button class="btn btn-outline" id="lockLayerBtn"><i class="fas fa-lock-open"></i> Lock Layer</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Layer Stack</label>
                    <div id="layerList" class="layer-list"></div>
                </div>

                <div class="form-group">
                    <label>Actions</label>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" id="saveVersionBtn"><i class="fas fa-save"></i> Save Version</button>
                        <button class="btn btn-outline" id="exportJsonBtn"><i class="fas fa-file-code"></i> Design JSON</button>
                        <button class="btn btn-outline" id="exportPngBtn"><i class="fas fa-image"></i> PNG Proof</button>
                        <button class="btn btn-outline" id="postToCommunityBtn"><i class="fas fa-paper-plane"></i> Post to Owner Community</button>
                       <button class="btn btn-outline" id="goToProofingBtn"><i class="fas fa-arrow-right"></i> Design Proofing</button>
                        <button class="btn btn-outline" id="getQuoteBtn"><i class="fas fa-file-invoice-dollar"></i> Get Price Quote</button>
                    </div>
                    <div class="version-list" id="versionList"></div>
                </div>

                <div class="help-grid">
                    <div class="help-item"><strong>Auto-save:</strong> Versions are stored locally every 10 minutes and whenever you manually save.</div>
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
const postToCommunityBtn = document.getElementById('postToCommunityBtn');
const versionList = document.getElementById('versionList');
const undoBtn = document.getElementById('undoBtn');
const redoBtn = document.getElementById('redoBtn');
const safeStatus = document.getElementById('safeStatus');
const fontFamily = document.getElementById('fontFamily');
const fontSize = document.getElementById('fontSize');
const boldBtn = document.getElementById('boldBtn');
const italicBtn = document.getElementById('italicBtn');
const underlineBtn = document.getElementById('underlineBtn');
const duplicateBtn = document.getElementById('duplicateBtn');
const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
const nudgeUpBtn = document.getElementById('nudgeUpBtn');
const nudgeDownBtn = document.getElementById('nudgeDownBtn');
const nudgeLeftBtn = document.getElementById('nudgeLeftBtn');
const nudgeRightBtn = document.getElementById('nudgeRightBtn');
const centerHorizontalBtn = document.getElementById('centerHorizontalBtn');
const centerVerticalBtn = document.getElementById('centerVerticalBtn');
const flipHorizontalBtn = document.getElementById('flipHorizontalBtn');
const flipVerticalBtn = document.getElementById('flipVerticalBtn');
const resetTransformBtn = document.getElementById('resetTransformBtn');
const lockLayerBtn = document.getElementById('lockLayerBtn');
const canvasType = document.getElementById('canvasType');
const canvasColor = document.getElementById('canvasColor');
const placementMethod = document.getElementById('placementMethod');
const logoSliderControls = document.getElementById('logoSliderControls');
const goToProofingBtn = document.getElementById('goToProofingBtn');
const canvasColorPalette = document.getElementById('canvasColorPalette');
const threadColorPalette = document.getElementById('threadColorPalette');
const canvasColorCurrentLabel = document.getElementById('canvasColorCurrentLabel');
const threadColorCurrentLabel = document.getElementById('threadColorCurrentLabel');
const canvasColorDropdown = document.getElementById('canvasColorDropdown');
const threadColorDropdown = document.getElementById('threadColorDropdown');
const placementButtonGrid = document.getElementById('placementButtonGrid');
const placementButtons = Array.from(document.querySelectorAll('.placement-btn'));
const modelRotation = document.getElementById('modelRotation');
const modelRotationValue = document.getElementById('modelRotationValue');
const previewModel = document.getElementById('previewModel');
const getQuoteBtn = document.getElementById('getQuoteBtn');


const presets = {
    'XS': { width: 4.0, height: 4.0 },
    'S': { width: 4.8, height: 4.8 },
    'M': { width: 5.6, height: 5.6 },
    'L': { width: 6.4, height: 6.4 },
    'XL': { width: 7.2, height: 7.2 }
};

const state = {
    elements: [],
    selectedId: null,
    hoopPreset: hoopPreset.value,
    threadColor: threadColor.value,
    showSafeArea: true,
    versions: [],
    canvasType: canvasType.value,
    canvasColor: canvasColor.value,
    placementMethod: placementMethod.value,
    versionCounter: 1,
    history: [],
    future: [],
    modelRotation: Number(modelRotation?.value || -20)
};

const storageKey = 'embroider_design_editor';

const placementOptionsByCanvasType = {
    tshirt: [
        { value: 'center-chest', label: 'Center Chest' },
        { value: 'left-chest', label: 'Left Chest' },
        { value: 'right-chest', label: 'Right Chest' },
        { value: 'full-front', label: 'Full Front' },
        { value: 'back-center', label: 'Back Center' },
        { value: 'sleeve', label: 'Sleeve' }
    ],
    cap: [
        { value: 'front-center', label: 'Front Center' },
        { value: 'left-panel', label: 'Left Panel' },
        { value: 'right-panel', label: 'Right Panel' },
        { value: 'back-strap', label: 'Back Strap' }
    ],
    'tote-bag': [
        { value: 'center', label: 'Center' },
        { value: 'upper-center', label: 'Upper Center' },
        { value: 'bottom-center', label: 'Bottom Center' }
    ],
    'plain-canvas': [
        { value: 'center', label: 'Center' },
        { value: 'top-left', label: 'Top Left' },
        { value: 'top-right', label: 'Top Right' },
        { value: 'bottom-left', label: 'Bottom Left' },
        { value: 'bottom-right', label: 'Bottom Right' }
    ]
};

function getCanvasTypeGroup(canvasTypeValue = state.canvasType) {
    if ((canvasTypeValue || '').startsWith('tshirt')) return 'tshirt';
    if ((canvasTypeValue || '').startsWith('cap')) return 'cap';
    if (canvasTypeValue === 'tote-bag') return 'tote-bag';
    return 'plain-canvas';
}

function getPlacementOptions(canvasTypeValue = state.canvasType) {
    return placementOptionsByCanvasType[getCanvasTypeGroup(canvasTypeValue)] || placementOptionsByCanvasType.tshirt;
}

function toPlacementLabel(placementValue) {
    if (!placementValue) return 'selected placement';
    return placementValue
        .split('-')
        .map(part => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function updateTextInputByPlacement() {
    const selectedPlacement = placementMethod.value || state.placementMethod || '';
    const placementLabel = toPlacementLabel(selectedPlacement);
    textInput.placeholder = `Enter text for ${placementLabel}`;
    const hint = document.getElementById('textPlacementHint');
    if (hint) {
        hint.textContent = `Text will be placed at ${placementLabel}.`;
    }
}

function syncPlacementButtons() {
    if (!placementButtons.length) return;
    const normalized = state.placementMethod || placementMethod.value;
    placementButtons.forEach(button => {
        const isActive = button.dataset.placement === normalized;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

function updatePreviewModel() {
    if (!previewModel) return;
    previewModel.style.backgroundColor = state.canvasColor;
    previewModel.style.setProperty('--model-rotation', `${state.modelRotation}deg`);
    if (modelRotationValue) {
        modelRotationValue.textContent = `${state.modelRotation}°`;
    }
}

function configurePlacementButtons() {
    if (!placementButtons.length) return;
    const buttonMapByType = {
        tshirt: [
            { label: 'Front', value: 'center-chest' },
            { label: 'Back', value: 'back-center' },
            { label: 'Left', value: 'left-chest' },
            { label: 'Right', value: 'right-chest' }
        ],
        cap: [
            { label: 'Front', value: 'front-center' },
            { label: 'Back', value: 'back-strap' },
            { label: 'Left', value: 'left-panel' },
            { label: 'Right', value: 'right-panel' }
        ],
        'tote-bag': [
            { label: 'Front', value: 'center' },
            { label: 'Back', value: 'bottom-center' },
            { label: 'Left', value: 'upper-center' },
            { label: 'Right', value: 'center' }
        ],
        'plain-canvas': [
            { label: 'Front', value: 'center' },
            { label: 'Back', value: 'bottom-right' },
            { label: 'Left', value: 'top-left' },
            { label: 'Right', value: 'top-right' }
        ]
    };
    const config = buttonMapByType[getCanvasTypeGroup()] || buttonMapByType.tshirt;
    placementButtons.forEach((button, index) => {
        const details = config[index] || config[0];
        button.dataset.placement = details.value;
        button.textContent = details.label;
    });
}

function syncPlacementOptions(shouldPushHistory = false, shouldSaveState = false) {
    const options = getPlacementOptions(state.canvasType);
    const previousPlacement = state.placementMethod;
    placementMethod.innerHTML = options.map(option => `<option value="${option.value}">${option.label}</option>`).join('');

    const validPlacement = options.some(option => option.value === previousPlacement)
        ? previousPlacement
        : options[0].value;

    state.placementMethod = validPlacement;
    placementMethod.value = validPlacement;
    configurePlacementButtons();
    updateTextInputByPlacement();
    syncPlacementButtons();

    if (shouldPushHistory && previousPlacement !== validPlacement) {
        pushHistory();
    }
    if (shouldSaveState && previousPlacement !== validPlacement) {
        saveState();
    }
}

function getCanvasColorOptions() {
    return Array.from(canvasColor.options).map(option => ({
        value: option.value.toLowerCase(),
        name: option.textContent.trim()
    }));
}
function getThreadColorOptions() {
    return Array.from(threadColor.options).map(option => ({
        value: option.value.toLowerCase(),
        name: option.textContent.trim()
    }));
}

function getColorOptionByValue(options, value, fallbackValue) {
    const normalizedValue = (value || fallbackValue || '').toLowerCase();
    return options.find(option => option.value === normalizedValue) || options[0] || { value: fallbackValue, name: fallbackValue };
}

function setPaletteCurrentLabel(target, option) {
    if (!target || !option) return;
    target.innerHTML = `
        <span class="color-swatch" style="background: ${option.value};"></span>
        <span class="color-palette-name">${option.name}</span>
    `;
}

function renderCanvasColorPalette() {
    if (!canvasColorPalette) return;
    const currentColor = (state.canvasColor || canvasColor.value || '').toLowerCase();
    const options = getCanvasColorOptions();
    const currentOption = getColorOptionByValue(options, currentColor, '#f8fafc');
    canvasColorPalette.innerHTML = options.map(option => `
        <button
            type="button"
            class="color-palette-btn ${option.value === currentColor ? 'active' : ''}"
            data-color="${option.value}"
            title="${option.name}"
            aria-label="Canvas color ${option.name}"
            aria-pressed="${option.value === currentColor ? 'true' : 'false'}"
        >
            <span class="color-swatch" style="background: ${option.value};"></span>
            <span class="color-palette-name">${option.name}</span>
        </button>
    `).join('');
    setPaletteCurrentLabel(canvasColorCurrentLabel, currentOption);
}

function renderThreadColorPalette() {
    if (!threadColorPalette) return;
    const currentColor = (state.threadColor || threadColor.value || '').toLowerCase();
    const options = getThreadColorOptions();
    const currentOption = getColorOptionByValue(options, currentColor, '#1d4ed8');
    threadColorPalette.innerHTML = options.map(option => `
        <button
            type="button"
            class="color-palette-btn ${option.value === currentColor ? 'active' : ''}"
            data-color="${option.value}"
            title="${option.name}"
            aria-label="Thread color ${option.name}"
            aria-pressed="${option.value === currentColor ? 'true' : 'false'}"
        >
            <span class="color-swatch" style="background: ${option.value};"></span>
            <span class="color-palette-name">${option.name}</span>
        </button>
    `).join('');
    setPaletteCurrentLabel(threadColorCurrentLabel, currentOption);
}

function setCanvasColor(colorValue, shouldPushHistory = false, shouldSaveState = false) {
    if (!colorValue) return;
    const normalizedColor = colorValue.toLowerCase();
    state.canvasColor = normalizedColor;
    canvasColor.value = normalizedColor;
    render();
    renderCanvasColorPalette();
    if (shouldPushHistory) {
        pushHistory();
    }
    if (shouldSaveState) {
        saveState();
    }
}

function normalizeCanvasColor(colorValue) {
    const normalizedValue = (colorValue || '').toLowerCase();
    const availableColors = new Set(getCanvasColorOptions().map(option => option.value));
    return availableColors.has(normalizedValue) ? normalizedValue : '#f8fafc';
}

function normalizeCanvasColorState() {
    state.canvasColor = normalizeCanvasColor(state.canvasColor || canvasColor.value);
    canvasColor.value = state.canvasColor;
}


function normalizeElements(elements) {
    return (elements || []).map(element => ({
        scaleX: 1,
        scaleY: 1,
        locked: false,
        ...element
    }));
}

function loadState() {
    const saved = localStorage.getItem(storageKey);
    if (!saved) {
        normalizeCanvasColorState();
        pushHistory();
        syncPlacementOptions();
        render();
        renderCanvasColorPalette();
        renderThreadColorPalette();
        return;
    }
    const parsed = JSON.parse(saved);
    Object.assign(state, parsed);
    state.modelRotation = Number(parsed.modelRotation ?? state.modelRotation ?? -20);
     state.elements = normalizeElements(state.elements);
    state.history = [];
    state.future = [];
    hoopPreset.value = state.hoopPreset || 'M';
    threadColor.value = state.threadColor || '#1d4ed8';
    safeAreaToggle.value = state.showSafeArea ? 'on' : 'off';
    canvasType.value = state.canvasType || 'tshirt-crew';
    if (modelRotation) modelRotation.value = String(state.modelRotation ?? -20);
     normalizeCanvasColorState();
    syncPlacementOptions();
    rebuildImages();
    pushHistory();
    render();
    renderCanvasColorPalette();
     renderThreadColorPalette();
    renderVersions();
}


function saveState() {
    localStorage.setItem(storageKey, JSON.stringify({
        elements: state.elements,
        hoopPreset: state.hoopPreset,
        threadColor: state.threadColor,
        showSafeArea: state.showSafeArea,
        canvasType: state.canvasType,
        canvasColor: state.canvasColor,
        placementMethod: state.placementMethod,
        modelRotation: state.modelRotation,
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
         canvasType: state.canvasType,
        canvasColor: state.canvasColor,
        placementMethod: state.placementMethod,
        modelRotation: state.modelRotation,
        selectedId: state.selectedId
    }));
    if (state.history.length > 30) {
        state.history.shift();
    }
    state.future = [];
}

function restoreFromHistory(entry) {
    const restored = JSON.parse(entry);
     state.elements = normalizeElements(restored.elements);
    state.hoopPreset = restored.hoopPreset;
    state.threadColor = restored.threadColor;
    state.showSafeArea = restored.showSafeArea;
     state.canvasType = restored.canvasType || 'tshirt-crew';
    state.canvasColor = normalizeCanvasColor(restored.canvasColor);
    state.placementMethod = restored.placementMethod || 'center-chest';
    state.modelRotation = Number(restored.modelRotation ?? state.modelRotation ?? -20);
    state.selectedId = restored.selectedId;
    hoopPreset.value = state.hoopPreset;
    threadColor.value = state.threadColor;
    safeAreaToggle.value = state.showSafeArea ? 'on' : 'off';
    canvasType.value = state.canvasType;
    if (modelRotation) modelRotation.value = String(state.modelRotation ?? -20);
    normalizeCanvasColorState();
    syncPlacementOptions();
    rebuildImages();
    render();
    renderCanvasColorPalette();
    renderThreadColorPalette();
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
    const preset = presets[state.hoopPreset] || presets.M;
    const aspect = preset.width / preset.height;
    const baseWidth = canvas.width * 0.8;
    const width = baseWidth;
    const height = baseWidth / aspect;
    return { width, height };
}

function drawCanvas() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#ddddda';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const hoop = getHoopDimensions();
    const hoopX = (canvas.width - hoop.width) / 2;
    const hoopY = (canvas.height - hoop.height) / 2;

     drawCanvasGuide(hoopX, hoopY, hoop.width, hoop.height);
    drawPlacementGuide(hoopX, hoopY, hoop.width, hoop.height);

    if (state.showSafeArea) {
        ctx.save();
        ctx.strokeStyle = '#1f2937';
        ctx.setLineDash([8, 8]);
        ctx.lineWidth = 1.5;
        ctx.strokeRect(hoopX + 24, hoopY + 24, hoop.width - 48, hoop.height - 48);
        ctx.restore();
    }

    state.elements.forEach(element => {
        ctx.save();
        ctx.translate(element.x, element.y);
        ctx.rotate(element.rotation * Math.PI / 180);
        const scaleX = (element.scaleX || 1) * element.scale;
        const scaleY = (element.scaleY || 1) * element.scale;
        ctx.scale(scaleX, scaleY);
        if (element.type === 'text') {
            ctx.fillStyle = element.color;
            ctx.font = getTextFont(element);
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(element.text, 0, 0);
             if (element.underline) {
                const metrics = getTextMetrics(element);
                const underlineY = metrics.height * 0.35;
                ctx.strokeStyle = element.color;
                ctx.lineWidth = Math.max(1.5, element.fontSize / 16);
                ctx.beginPath();
                ctx.moveTo(-metrics.width / 2, underlineY);
                ctx.lineTo(metrics.width / 2, underlineY);
                ctx.stroke();
            }
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

function getTextFont(element) {
    const isItalic = element.fontItalic ? 'italic' : 'normal';
    const isBold = element.fontWeight || 'normal';
    const family = element.fontFamily || 'Inter';
    return `${isItalic} ${isBold} ${element.fontSize}px '${family}', sans-serif`;
}

function getTextMetrics(element) {
    ctx.save();
    ctx.font = getTextFont(element);
    const width = Math.max(30, ctx.measureText(element.text).width);
    ctx.restore();
    const height = element.fontSize * 1.25;
    return { width, height };
}

function drawCanvasGuide(hoopX, hoopY, hoopWidth, hoopHeight) {
    if (state.canvasType.startsWith('cap')) {
        drawCapGuide(hoopX, hoopY, hoopWidth, hoopHeight);
        return;
    }
    if (state.canvasType === 'tote-bag') {
        drawToteBagGuide(hoopX, hoopY, hoopWidth, hoopHeight);
        return;
    }
    if (state.canvasType === 'plain-canvas') {
        drawPlainCanvasGuide(hoopX, hoopY, hoopWidth, hoopHeight);
        return;
    }
    const shirtCenterX = canvas.width / 2;
    const shirtTop = Math.max(18, hoopY - 86);
    const shirtWidth = Math.min(canvas.width - 64, hoopWidth + 220);
    const shirtHeight = Math.min(canvas.height - shirtTop - 18, hoopHeight + 210);
    const shirtBottom = shirtTop + shirtHeight;
    const shoulderY = shirtTop + shirtHeight * 0.18;
    const sleeveWidth = Math.max(70, shirtWidth * 0.23);
    const sleeveDrop = Math.max(86, shirtHeight * 0.29);
    const bodyWidth = shirtWidth - sleeveWidth * 2.1;
    const leftBody = shirtCenterX - bodyWidth / 2;
    const rightBody = shirtCenterX + bodyWidth / 2;
    const leftSleeve = leftBody - sleeveWidth;
    const rightSleeve = rightBody + sleeveWidth;
    const hemY = shirtBottom - 8;
    const neckOuterRadius = Math.max(48, bodyWidth * 0.14);
    const neckInnerRadius = neckOuterRadius - 11;
    const neckY = shirtTop + 20;

    ctx.save();
    ctx.beginPath();
    ctx.moveTo(leftBody + 22, shirtTop + 8);
    ctx.lineTo(rightBody - 22, shirtTop + 8);
    ctx.lineTo(rightSleeve + 14, shoulderY + 30);
    ctx.lineTo(rightSleeve - 6, shoulderY + sleeveDrop);
    ctx.lineTo(rightBody + 8, shoulderY + sleeveDrop + 2);
    ctx.lineTo(rightBody - 10, hemY);
    ctx.lineTo(leftBody + 10, hemY);
    ctx.lineTo(leftBody - 8, shoulderY + sleeveDrop + 2);
    ctx.lineTo(leftSleeve + 6, shoulderY + sleeveDrop);
    ctx.lineTo(leftSleeve - 14, shoulderY + 30);
    ctx.closePath();
    ctx.fillStyle = state.canvasColor;
    ctx.fill();
    ctx.strokeStyle = '#2f2f2f';
    ctx.lineWidth = 2.2;
    ctx.stroke();

    // Neck band and inner opening.
    ctx.beginPath();
    ctx.ellipse(shirtCenterX, neckY, neckOuterRadius, neckOuterRadius * 0.62, 0, Math.PI, 0, true);
    ctx.fillStyle = '#efefef';
    ctx.fill();
    ctx.strokeStyle = '#2f2f2f';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.beginPath();
    ctx.ellipse(shirtCenterX, neckY + 4, neckInnerRadius, neckInnerRadius * 0.56, 0, Math.PI, 0, true);
    ctx.fillStyle = '#d0d0d0';
    ctx.fill();
    ctx.strokeStyle = '#5f5f5f';
    ctx.lineWidth = 1;
    ctx.stroke();

     // Stitch-like guide lines on sleeves and shoulder seams.
    ctx.strokeStyle = '#b9b9b9';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(leftSleeve - 6, shoulderY + 34);
    ctx.lineTo(leftSleeve + 14, shoulderY + sleeveDrop - 6);
    ctx.moveTo(rightSleeve + 6, shoulderY + 34);
    ctx.lineTo(rightSleeve - 14, shoulderY + sleeveDrop - 6);
    ctx.moveTo(leftBody + 28, shirtTop + 12);
    ctx.lineTo(leftBody + 14, shoulderY + 42);
    ctx.moveTo(rightBody - 28, shirtTop + 12);
    ctx.lineTo(rightBody - 14, shoulderY + 42);
    ctx.stroke();

    ctx.strokeStyle = '#9c9c9c';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(leftBody + 12, hemY - 2);
    ctx.lineTo(rightBody - 12, hemY - 2);
    ctx.stroke();

    if (state.canvasType === 'tshirt-vneck') {
        ctx.strokeStyle = '#2f2f2f';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(shirtCenterX - 24, neckY + 8);
        ctx.lineTo(shirtCenterX, neckY + 30);
        ctx.lineTo(shirtCenterX + 24, neckY + 8);
        ctx.stroke();
    }

    if (state.canvasType === 'tshirt-polo') {
        ctx.strokeStyle = '#2f2f2f';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(shirtCenterX, neckY + 10);
        ctx.lineTo(shirtCenterX, neckY + 44);
        ctx.moveTo(shirtCenterX - 18, neckY + 16);
        ctx.lineTo(shirtCenterX, neckY + 30);
        ctx.lineTo(shirtCenterX + 18, neckY + 16);
        ctx.stroke();
    }

    if (state.canvasType === 'tshirt-pocket') {
        const pocketW = Math.max(44, bodyWidth * 0.16);
        const pocketH = Math.max(46, shirtHeight * 0.12);
        const pocketX = shirtCenterX - bodyWidth * 0.22 - pocketW / 2;
        const pocketY = shoulderY + 42;
        ctx.strokeStyle = '#4b5563';
        ctx.lineWidth = 1.2;
        ctx.strokeRect(pocketX, pocketY, pocketW, pocketH);
    }

    if (state.canvasType === 'tshirt-tank') {
        ctx.strokeStyle = '#6b7280';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(leftBody + 20, shirtTop + 18);
        ctx.lineTo(leftBody + 20, shoulderY + 62);
        ctx.moveTo(rightBody - 20, shirtTop + 18);
        ctx.lineTo(rightBody - 20, shoulderY + 62);
        ctx.stroke();
    }

    ctx.restore();
}

function drawCapGuide(hoopX, hoopY, hoopWidth, hoopHeight) {
    const centerX = canvas.width / 2;
    const centerY = hoopY + hoopHeight * 0.58;
    const capWidth = hoopWidth + 150;
    const capHeight = hoopHeight * 0.72;

    ctx.save();
    ctx.fillStyle = state.canvasColor;
    ctx.strokeStyle = '#2f2f2f';
    ctx.lineWidth = 2;

    if (state.canvasType === 'cap-bucket') {
        ctx.beginPath();
        ctx.ellipse(centerX, centerY, capWidth * 0.34, capHeight * 0.32, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
        ctx.beginPath();
        ctx.ellipse(centerX, centerY + capHeight * 0.3, capWidth * 0.5, capHeight * 0.18, 0, 0, Math.PI * 2);
        ctx.fillStyle = shadeColor(state.canvasColor, -15);
        ctx.fill();
        ctx.stroke();
    } else {
        ctx.beginPath();
        ctx.ellipse(centerX, centerY, capWidth * 0.36, capHeight * 0.35, 0, Math.PI, 0, true);
        ctx.fill();
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(centerX - capWidth * 0.2, centerY + capHeight * 0.14);
        ctx.quadraticCurveTo(centerX, centerY + capHeight * 0.28, centerX + capWidth * 0.2, centerY + capHeight * 0.14);
        ctx.fillStyle = shadeColor(state.canvasColor, -20);
        ctx.fill();
        ctx.stroke();
    }

    ctx.restore();
}

function drawToteBagGuide(hoopX, hoopY, hoopWidth, hoopHeight) {
    const bagWidth = hoopWidth + 180;
    const bagHeight = hoopHeight + 120;
    const bagX = (canvas.width - bagWidth) / 2;
    const bagY = Math.max(24, hoopY - 40);

    ctx.save();
    ctx.fillStyle = state.canvasColor;
    ctx.strokeStyle = '#2f2f2f';
    ctx.lineWidth = 2;
    ctx.fillRect(bagX, bagY + 70, bagWidth, bagHeight - 70);
    ctx.strokeRect(bagX, bagY + 70, bagWidth, bagHeight - 70);

    ctx.beginPath();
    ctx.moveTo(bagX + bagWidth * 0.27, bagY + 70);
    ctx.bezierCurveTo(bagX + bagWidth * 0.25, bagY + 18, bagX + bagWidth * 0.75, bagY + 18, bagX + bagWidth * 0.73, bagY + 70);
    ctx.stroke();
    ctx.restore();
}

function drawPlainCanvasGuide(hoopX, hoopY, hoopWidth, hoopHeight) {
    const areaWidth = hoopWidth + 220;
    const areaHeight = hoopHeight + 180;
    const areaX = (canvas.width - areaWidth) / 2;
    const areaY = (canvas.height - areaHeight) / 2;

    ctx.save();
    ctx.fillStyle = state.canvasColor;
    ctx.fillRect(areaX, areaY, areaWidth, areaHeight);
    ctx.strokeStyle = '#2f2f2f';
    ctx.lineWidth = 2;
    ctx.strokeRect(areaX, areaY, areaWidth, areaHeight);
    ctx.restore();
}

function drawPlacementGuide(hoopX, hoopY, hoopWidth, hoopHeight) {
    const placementsByType = {
        tshirt: {
            'center-chest': { x: canvas.width / 2, y: hoopY + hoopHeight * 0.45, label: 'Center Chest' },
            'left-chest': { x: canvas.width / 2 - hoopWidth * 0.2, y: hoopY + hoopHeight * 0.43, label: 'Left Chest' },
            'right-chest': { x: canvas.width / 2 + hoopWidth * 0.2, y: hoopY + hoopHeight * 0.43, label: 'Right Chest' },
            'full-front': { x: canvas.width / 2, y: hoopY + hoopHeight * 0.5, label: 'Full Front' },
            'back-center': { x: canvas.width / 2, y: hoopY + hoopHeight * 0.58, label: 'Back Center' },
            'sleeve': { x: canvas.width / 2 - hoopWidth * 0.36, y: hoopY + hoopHeight * 0.42, label: 'Sleeve' }
        },
        cap: {
            'front-center': { x: canvas.width / 2, y: hoopY + hoopHeight * 0.42, label: 'Front Center' },
            'left-panel': { x: canvas.width / 2 - hoopWidth * 0.22, y: hoopY + hoopHeight * 0.44, label: 'Left Panel' },
            'right-panel': { x: canvas.width / 2 + hoopWidth * 0.22, y: hoopY + hoopHeight * 0.44, label: 'Right Panel' },
            'back-strap': { x: canvas.width / 2, y: hoopY + hoopHeight * 0.63, label: 'Back Strap' }
        },
        'tote-bag': {
            center: { x: canvas.width / 2, y: hoopY + hoopHeight * 0.52, label: 'Center' },
            'upper-center': { x: canvas.width / 2, y: hoopY + hoopHeight * 0.36, label: 'Upper Center' },
            'bottom-center': { x: canvas.width / 2, y: hoopY + hoopHeight * 0.68, label: 'Bottom Center' }
        },
        'plain-canvas': {
            center: { x: canvas.width / 2, y: hoopY + hoopHeight * 0.5, label: 'Center' },
            'top-left': { x: canvas.width / 2 - hoopWidth * 0.24, y: hoopY + hoopHeight * 0.32, label: 'Top Left' },
            'top-right': { x: canvas.width / 2 + hoopWidth * 0.24, y: hoopY + hoopHeight * 0.32, label: 'Top Right' },
            'bottom-left': { x: canvas.width / 2 - hoopWidth * 0.24, y: hoopY + hoopHeight * 0.68, label: 'Bottom Left' },
            'bottom-right': { x: canvas.width / 2 + hoopWidth * 0.24, y: hoopY + hoopHeight * 0.68, label: 'Bottom Right' }
        }
    };

     const canvasTypeGroup = getCanvasTypeGroup();
    const placements = placementsByType[canvasTypeGroup] || placementsByType.tshirt;
    const firstPlacement = getPlacementOptions()[0]?.value;
    const point = placements[state.placementMethod] || placements[firstPlacement] || Object.values(placements)[0];
    const largePlacements = new Set(['full-front']);
    const guideSize = largePlacements.has(state.placementMethod) ? 92 : 50;

    ctx.save();
    ctx.strokeStyle = '#f59e0b';
    ctx.fillStyle = 'rgba(245, 158, 11, 0.12)';
    ctx.lineWidth = 1.8;
    ctx.setLineDash([5, 4]);
    ctx.beginPath();
    ctx.rect(point.x - guideSize / 2, point.y - guideSize / 2, guideSize, guideSize);
    ctx.fill();
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = '#92400e';
    ctx.font = "600 13px 'Inter', sans-serif";
    ctx.fillText(point.label, point.x - guideSize / 2, point.y - guideSize / 2 - 8);
    ctx.restore();
}

function shadeColor(hex, percent) {
    const clean = (hex || '#f8fafc').replace('#', '');
    const value = parseInt(clean, 16);
    const amt = Math.round(2.55 * percent);
    const r = Math.max(0, Math.min(255, (value >> 16) + amt));
    const g = Math.max(0, Math.min(255, ((value >> 8) & 0x00ff) + amt));
    const b = Math.max(0, Math.min(255, (value & 0x0000ff) + amt));
    return `#${(0x1000000 + (r << 16) + (g << 8) + b).toString(16).slice(1)}`;
}


function getElementBounds(element) {
    if (element.type === 'text') {
        const metrics = getTextMetrics(element);
        const width = metrics.width;
        const height = metrics.height;
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
         const scaledWidth = bounds.width * Math.abs((element.scaleX || 1) * element.scale);
        const scaledHeight = bounds.height * Math.abs((element.scaleY || 1) * element.scale);
        const left = element.x - scaledWidth / 2;
        const top = element.y - scaledHeight / 2;
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
         const label = document.createElement('span');
        label.className = 'layer-label';
        const lockIcon = element.locked ? '🔒 ' : '';
        label.textContent = `${lockIcon}${element.type === 'text' ? 'Text' : 'Image'}: ${element.label}`;

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'layer-delete-btn';
        deleteBtn.title = 'Delete layer';
        deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
        deleteBtn.onclick = event => {
            event.stopPropagation();
            if (element.locked) return;
            deleteLayer(element.id);
        };

        item.appendChild(label);
        item.appendChild(deleteBtn);
        item.onclick = () => {
            state.selectedId = element.id;
            updateControlValues();
            render();
        };
        layerList.appendChild(item);
    });
}

function deleteLayer(elementId) {
    const index = state.elements.findIndex(element => element.id === elementId);
    if (index === -1 || state.elements[index].locked) return;
    state.elements.splice(index, 1);

    if (state.selectedId === elementId) {
        state.selectedId = null;
    }

    pushHistory();
    render();
    saveState();
}

function renderVersions() {
    versionList.innerHTML = '';
    state.versions.slice().reverse().forEach(version => {
        const card = document.createElement('div');
        card.className = 'version-card';
         card.innerHTML = `
            <span>${version.name}</span>
            <div class="version-actions">
                <button class="btn btn-outline btn-sm" data-action="load">Load</button>
                <button class="btn btn-danger btn-sm" data-action="delete">Delete</button>
            </div>
        `;

        card.querySelector('[data-action="load"]').onclick = () => {
            state.elements = normalizeElements(version.data.elements);
            state.hoopPreset = version.data.hoopPreset;
            state.threadColor = version.data.threadColor;
            state.showSafeArea = version.data.showSafeArea;
            state.canvasType = version.data.canvasType || 'tshirt-crew';
            state.canvasColor = normalizeCanvasColor(version.data.canvasColor);
            state.placementMethod = version.data.placementMethod || 'center-chest';
            state.selectedId = null;
            hoopPreset.value = state.hoopPreset;
            threadColor.value = state.threadColor;
            safeAreaToggle.value = state.showSafeArea ? 'on' : 'off';
            canvasType.value = state.canvasType;
            normalizeCanvasColorState();
            syncPlacementOptions();
            rebuildImages();
            pushHistory();
            render();
            renderCanvasColorPalette();
             renderThreadColorPalette();
            saveState();
        };
        
          card.querySelector('[data-action="delete"]').onclick = () => {
            state.versions = state.versions.filter(savedVersion => savedVersion.savedAt !== version.savedAt);
            renderVersions();
            saveState();
        };

        versionList.appendChild(card);
    });
}

function render() {
    updatePreviewModel();
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
         logoSliderControls.style.display = 'none';
        fontFamily.value = 'Inter';
        fontSize.value = 76;
        setToggleState(boldBtn, false);
        setToggleState(italicBtn, false);
        setToggleState(underlineBtn, false);
         lockLayerBtn.innerHTML = '<i class="fas fa-lock-open"></i> Lock Layer';
        setToggleState(lockLayerBtn, false);
        return;
    }
    scaleSlider.value = selected.scale;
    rotationSlider.value = selected.rotation;
    scaleValue.textContent = `${Math.round(selected.scale * 100)}%`;
    rotationValue.textContent = `${selected.rotation}°`;
     logoSliderControls.style.display = selected.type === 'image' ? 'block' : 'none';

    if (selected.type === 'text') {
        fontFamily.value = selected.fontFamily || 'Inter';
        fontSize.value = selected.fontSize;
        setToggleState(boldBtn, (selected.fontWeight || 'normal') === 'bold');
        setToggleState(italicBtn, !!selected.fontItalic);
        setToggleState(underlineBtn, !!selected.underline);
    }
     setToggleState(lockLayerBtn, !!selected.locked);
    lockLayerBtn.innerHTML = selected.locked
        ? '<i class="fas fa-lock"></i> Unlock Layer'
        : '<i class="fas fa-lock-open"></i> Lock Layer';
}

function setToggleState(button, enabled) {
    button.classList.toggle('active', enabled);
}

function getPlacementPoint() {
    const hoop = getHoopDimensions();
    const hoopY = (canvas.height - hoop.height) / 2;
    const placementsByType = {
        tshirt: {
            'center-chest': { x: canvas.width / 2, y: hoopY + hoop.height * 0.45 },
            'left-chest': { x: canvas.width / 2 - hoop.width * 0.2, y: hoopY + hoop.height * 0.43 },
            'right-chest': { x: canvas.width / 2 + hoop.width * 0.2, y: hoopY + hoop.height * 0.43 },
            'full-front': { x: canvas.width / 2, y: hoopY + hoop.height * 0.5 },
            'back-center': { x: canvas.width / 2, y: hoopY + hoop.height * 0.58 },
            sleeve: { x: canvas.width / 2 - hoop.width * 0.36, y: hoopY + hoop.height * 0.42 }
        },
        cap: {
            'front-center': { x: canvas.width / 2, y: hoopY + hoop.height * 0.42 },
            'left-panel': { x: canvas.width / 2 - hoop.width * 0.22, y: hoopY + hoop.height * 0.44 },
            'right-panel': { x: canvas.width / 2 + hoop.width * 0.22, y: hoopY + hoop.height * 0.44 },
            'back-strap': { x: canvas.width / 2, y: hoopY + hoop.height * 0.63 }
        },
        'tote-bag': {
            center: { x: canvas.width / 2, y: hoopY + hoop.height * 0.52 },
            'upper-center': { x: canvas.width / 2, y: hoopY + hoop.height * 0.36 },
            'bottom-center': { x: canvas.width / 2, y: hoopY + hoop.height * 0.68 }
        },
        'plain-canvas': {
            center: { x: canvas.width / 2, y: hoopY + hoop.height * 0.5 },
            'top-left': { x: canvas.width / 2 - hoop.width * 0.24, y: hoopY + hoop.height * 0.32 },
            'top-right': { x: canvas.width / 2 + hoop.width * 0.24, y: hoopY + hoop.height * 0.32 },
            'bottom-left': { x: canvas.width / 2 - hoop.width * 0.24, y: hoopY + hoop.height * 0.68 },
            'bottom-right': { x: canvas.width / 2 + hoop.width * 0.24, y: hoopY + hoop.height * 0.68 }
        }
    };

    const canvasTypeGroup = getCanvasTypeGroup();
    const placements = placementsByType[canvasTypeGroup] || placementsByType.tshirt;
    const firstPlacement = getPlacementOptions()[0]?.value;
    return placements[state.placementMethod] || placements[firstPlacement] || { x: canvas.width / 2, y: canvas.height / 2 };
}

function addText() {
    if (!textInput.value.trim()) return;
    const element = {
        id: `text-${Date.now()}`,
        type: 'text',
        text: textInput.value.trim(),
        label: textInput.value.trim().slice(0, 12),
        x: getPlacementPoint().x,
        y: getPlacementPoint().y,
        scale: 1,
        rotation: 0,
        fontSize: 76,
         fontFamily: fontFamily.value,
        fontWeight: 'normal',
        fontItalic: false,
        underline: false,
        color: state.threadColor,
        scaleX: 1,
        scaleY: 1,
        locked: false
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
                rotation: 0,
                scaleX: 1,
                scaleY: 1,
                locked: false
            };
            state.elements.push(element);
            state.selectedId = element.id;
            pushHistory();
            render();
            renderCanvasColorPalette();
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
         if (selected.locked) {
            state.selectedId = selected.id;
            render();
            return;
        }
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
    state.threadColor = threadColor.value.toLowerCase();
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (selected && selected.type === 'text') {
        selected.color = state.threadColor;
    }
    pushHistory();
    render();
    renderThreadColorPalette();
    saveState();
});

safeAreaToggle.addEventListener('change', () => {
    state.showSafeArea = safeAreaToggle.value === 'on';
    pushHistory();
    render();
    saveState();
});

canvasType.addEventListener('change', () => {
    state.canvasType = canvasType.value;
    syncPlacementOptions();
    pushHistory();
    render();
    saveState();
});

canvasColor.addEventListener('input', () => {
   setCanvasColor(canvasColor.value);
});

canvasColor.addEventListener('change', () => {
   setCanvasColor(canvasColor.value, true, true);
});

canvasColorPalette.addEventListener('click', event => {
    const button = event.target.closest('.color-palette-btn');
    if (!button) return;
    setCanvasColor(button.dataset.color, true, true);
     if (canvasColorDropdown) {
        canvasColorDropdown.removeAttribute('open');
    }
});

threadColorPalette.addEventListener('click', event => {
    const button = event.target.closest('.color-palette-btn');
    if (!button) return;
    threadColor.value = button.dataset.color;
    threadColor.dispatchEvent(new Event('change'));
    if (threadColorDropdown) {
        threadColorDropdown.removeAttribute('open');
    }
});

placementMethod.addEventListener('change', () => {
    state.placementMethod = placementMethod.value;
     syncPlacementButtons();
    updateTextInputByPlacement();
    pushHistory();
    render();
    saveState();
});

placementButtons.forEach(button => {
    button.addEventListener('click', () => {
        const targetPlacement = button.dataset.placement || '';
        const hasPlacement = Array.from(placementMethod.options).some(option => option.value === targetPlacement);
        if (!hasPlacement) return;
        placementMethod.value = targetPlacement;
        state.placementMethod = targetPlacement;
        syncPlacementButtons();
        updateTextInputByPlacement();
        pushHistory();
        render();
        saveState();
    });
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
    if (!selected || selected.locked) return;
    pushHistory();
    saveState();
});

rotationSlider.addEventListener('input', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected || selected.locked) return;
    selected.rotation = parseInt(rotationSlider.value, 10);
    rotationValue.textContent = `${selected.rotation}°`;
    render();
});

rotationSlider.addEventListener('change', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected || selected.locked) return;
    pushHistory();
    saveState();
});

fontFamily.addEventListener('change', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected || selected.locked || selected.type !== 'text') return;
    selected.fontFamily = fontFamily.value;
    pushHistory();
    render();
    saveState();
});

fontSize.addEventListener('change', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected || selected.locked || selected.type !== 'text') return;
    const parsed = Math.max(6, Math.min(128, parseInt(fontSize.value, 10) || 76));
    selected.fontSize = parsed;
    fontSize.value = parsed;
    pushHistory();
    render();
    saveState();
});

function toggleTextStyle(key, activeValue, fallbackValue = false) {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected || selected.locked || selected.type !== 'text') return;
    selected[key] = selected[key] === activeValue ? fallbackValue : activeValue;
    pushHistory();
    render();
    saveState();
}

boldBtn.addEventListener('click', () => toggleTextStyle('fontWeight', 'bold', 'normal'));
italicBtn.addEventListener('click', () => toggleTextStyle('fontItalic', true, false));
underlineBtn.addEventListener('click', () => toggleTextStyle('underline', true, false));

duplicateBtn.addEventListener('click', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected || selected.locked) return;
    const clone = {
        ...selected,
        id: `${selected.type}-${Date.now()}`,
        x: selected.x + 18,
        y: selected.y + 18,
        locked: false
    };
    state.elements.push(clone);
    state.selectedId = clone.id;
    pushHistory();
    render();
    saveState();
});

deleteSelectedBtn.addEventListener('click', () => {
    if (!state.selectedId) return;
    deleteLayer(state.selectedId);
});

function nudgeSelected(dx, dy) {
    const selected = state.elements.find(element => element.id === state.selectedId);
     if (!selected || selected.locked) return;
    selected.x += dx;
    selected.y += dy;
    pushHistory();
    render();
    saveState();
}

nudgeUpBtn.addEventListener('click', () => nudgeSelected(0, -5));
nudgeDownBtn.addEventListener('click', () => nudgeSelected(0, 5));
nudgeLeftBtn.addEventListener('click', () => nudgeSelected(-5, 0));
nudgeRightBtn.addEventListener('click', () => nudgeSelected(5, 0));

function updateElementWithHistory(mutator) {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected || selected.locked) return;
    mutator(selected);
    pushHistory();
    render();
    saveState();
}

centerHorizontalBtn.addEventListener('click', () => {
    updateElementWithHistory(selected => {
        selected.x = canvas.width / 2;
    });
});

centerVerticalBtn.addEventListener('click', () => {
    updateElementWithHistory(selected => {
        selected.y = canvas.height / 2;
    });
});

flipHorizontalBtn.addEventListener('click', () => {
    updateElementWithHistory(selected => {
        selected.scaleX = (selected.scaleX || 1) * -1;
    });
});

flipVerticalBtn.addEventListener('click', () => {
    updateElementWithHistory(selected => {
        selected.scaleY = (selected.scaleY || 1) * -1;
    });
});

resetTransformBtn.addEventListener('click', () => {
    updateElementWithHistory(selected => {
        selected.scale = 1;
        selected.scaleX = 1;
        selected.scaleY = 1;
        selected.rotation = 0;
    });
});

lockLayerBtn.addEventListener('click', () => {
    const selected = state.elements.find(element => element.id === state.selectedId);
    if (!selected) return;
    selected.locked = !selected.locked;
    pushHistory();
    render();
    saveState();
});

function moveLayer(direction) {
    const index = state.elements.findIndex(element => element.id === state.selectedId);
    if (index === -1 || state.elements[index].locked) return;
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
            showSafeArea: state.showSafeArea,
            canvasType: state.canvasType,
            canvasColor: state.canvasColor,
            placementMethod: state.placementMethod
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
        canvasType: state.canvasType,
        canvasColor: state.canvasColor,
        placementMethod: state.placementMethod,
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

goToProofingBtn.addEventListener('click', () => {
    window.location.href = 'design_proofing.php?from_design_editor=1';
});

if (getQuoteBtn) {
    getQuoteBtn.addEventListener('click', () => {
        window.location.href = 'pricing_quotation.php?from_design_editor=1';
    });
}

if (modelRotation) {
    modelRotation.addEventListener('input', () => {
        state.modelRotation = Number(modelRotation.value || 0);
        updatePreviewModel();
    });
    modelRotation.addEventListener('change', () => {
        pushHistory();
        saveState();
    });
}

postToCommunityBtn.addEventListener('click', () => {
    if (!state.elements.length) {
        alert('Please add at least one design element before posting to the owner community.');
        return;
    }

    const generatedTitle = `Design concept ${new Date().toLocaleDateString()}`;
    const elementSummary = state.elements
        .map(element => element.type === 'text' ? `Text: "${element.text}"` : `Image: ${element.label}`)
        .join(', ');

    const communityDraft = {
        source: 'design_editor',
        title: generatedTitle,
        category: 'Request',
        description: `I created this design in the editor and I would like feedback/quotes from shop owners.\n\nCanvas: ${state.canvasType} (${state.canvasColor})\nPlacement: ${state.placementMethod}\nHoop preset: ${state.hoopPreset}\nThread color: ${state.threadColor}\nElements: ${elementSummary}`,
        generatedAt: new Date().toISOString()
    };

    localStorage.setItem('embroider_community_post_draft', JSON.stringify(communityDraft));
    window.location.href = 'client_posting_community.php?from_design_editor=1';
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
            showSafeArea: state.showSafeArea,
            canvasType: state.canvasType,
            canvasColor: state.canvasColor,
            placementMethod: state.placementMethod
        }
    });
    if (state.versions.length > 12) {
        state.versions.shift();
    }
    renderVersions();
    saveState();
}, 600000);

normalizeCanvasColorState();
renderCanvasColorPalette();
renderThreadColorPalette();
loadState();
updateTextInputByPlacement();
</script>
</body>
</html>
