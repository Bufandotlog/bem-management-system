<?php
// admin/footer.php - Footer untuk halaman admin
$adminJsPath = __DIR__ . '/js/admin.js';
$adminJsVer  = file_exists($adminJsPath) ? filemtime($adminJsPath) : '1';
?>
        </main>
    </div>
    
    <!-- Admin JavaScript -->
    <script src="js/admin.js?v=<?php echo $adminJsVer; ?>"></script>
</body>
</html>