<?php
// admin/footer.php - Footer untuk halaman admin
$adminJsPath = __DIR__ . '/../js/admin.js';
$adminJsVer  = file_exists($adminJsPath) ? filemtime($adminJsPath) : '1';
?>
        </main>
    </div>
    
    <?php if (!empty($show_bottom_nav) && !empty($bottom_nav_tabs)): ?>
    <!-- Mobile Bottom Navigation Bar -->
    <nav class="mobile-bottom-nav" id="mobileBottomNav">
        <?php foreach ($bottom_nav_tabs as $tab): ?>
            <?php 
            $click_attr = isset($tab['onclick']) ? 'onclick="' . htmlspecialchars($tab['onclick'], ENT_QUOTES, 'UTF-8') . '"' : '';
            $active_cls = !empty($tab['active']) ? 'active' : '';
            ?>
            <a href="<?php echo htmlspecialchars($tab['url'], ENT_QUOTES, 'UTF-8'); ?>" class="mobile-nav-item <?php echo $active_cls; ?>" <?php echo $click_attr; ?>>
                <i class="<?php echo htmlspecialchars($tab['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                <span><?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (!empty($tab['badge'])): ?>
                    <span class="mobile-nav-badge"><?php echo (int)$tab['badge']; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (!empty($bottom_sheet_items)): ?>
    <!-- Bottom Sheet Drawer (untuk Konten BEM) -->
    <div class="bottom-sheet-overlay" id="bottomSheetOverlay" onclick="closeMobileBottomSheet()"></div>
    <div class="bottom-sheet-panel" id="bottomSheetPanel">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h4><i class="fas fa-university"></i> Konten BEM</h4>
            <button type="button" class="sheet-close-btn" onclick="closeMobileBottomSheet()">&times;</button>
        </div>
        <div class="sheet-menu-list">
            <?php foreach ($bottom_sheet_items as $sitem): ?>
                <a href="<?php echo htmlspecialchars($sitem['url'], ENT_QUOTES, 'UTF-8'); ?>" class="sheet-menu-item <?php echo !empty($sitem['active']) ? 'active' : ''; ?>">
                    <i class="<?php echo htmlspecialchars($sitem['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                    <span><?php echo htmlspecialchars($sitem['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Admin JavaScript -->
    <script src="<?php echo baseUrl('admin/js/admin.js'); ?>?v=<?php echo $adminJsVer; ?>"></script>
</body>
</html>