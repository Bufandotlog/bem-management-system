/**
 * MODULE: kepengurusan-dropdown.js
 * Custom dropdown untuk halaman kepengurusan dengan efek glassmorphism
 */

export function initKepengurusanDropdown() {
    // Hanya jalankan di halaman kepengurusan
    if (!document.body.classList.contains('page-kepengurusan')) return;
    
    console.log('✅ Inisialisasi custom dropdown kepengurusan');
    
    class CustomDropdown {
        constructor(container) {
            this.container = container;
            this.dropdown = container.querySelector('.custom-dropdown');
            this.trigger = container.querySelector('.custom-dropdown-trigger');
            this.menu = container.querySelector('.custom-dropdown-menu');
            this.items = container.querySelectorAll('.custom-dropdown-item');
            this.triggerText = container.querySelector('.trigger-text');
            this.hiddenForm = document.getElementById('customPeriodeForm');
            this.hiddenInput = document.getElementById('customPeriodeInput');
            
            this.isOpen = false;
            this.selectedValue = this.hiddenInput ? this.hiddenInput.value : null;
            this.keyboardActiveIndex = -1;
            
            this.init();
        }
        
        init() {
            if (!this.trigger || !this.menu) return;
            
            // Toggle dropdown
            this.trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggle();
            });
            
            // Pilih item
            this.items.forEach((item, index) => {
                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.selectItem(item);
                });
                
                // Simpan index untuk keyboard navigation
                item.dataset.index = index;
            });
            
            // Klik di luar untuk menutup
            document.addEventListener('click', (e) => {
                if (!this.container.contains(e.target)) {
                    this.close();
                }
            });
            
            // Tombol ESC untuk menutup
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.close();
                    this.trigger.focus();
                }
            });
            
            // Navigasi keyboard
            this.trigger.addEventListener('keydown', (e) => this.handleTriggerKeydown(e));
            this.menu.addEventListener('keydown', (e) => this.handleMenuKeydown(e));
            
            // Set selected awal
            this.updateSelectedItem();
        }
        
        handleTriggerKeydown(e) {
            if (e.key === 'ArrowDown' && !this.isOpen) {
                e.preventDefault();
                this.open();
                setTimeout(() => {
                    const selectedItem = this.container.querySelector('.custom-dropdown-item.selected');
                    if (selectedItem) {
                        selectedItem.focus();
                    } else if (this.items[0]) {
                        this.items[0].focus();
                    }
                }, 50);
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.toggle();
            }
        }
        
        handleMenuKeydown(e) {
            const items = Array.from(this.items);
            const currentIndex = items.indexOf(document.activeElement);
            
            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.focusNextItem(currentIndex);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    this.focusPrevItem(currentIndex);
                    break;
                case 'Home':
                    e.preventDefault();
                    if (items.length > 0) items[0].focus();
                    break;
                case 'End':
                    e.preventDefault();
                    if (items.length > 0) items[items.length - 1].focus();
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    const activeItem = document.activeElement;
                    if (activeItem.classList.contains('custom-dropdown-item')) {
                        this.selectItem(activeItem);
                    }
                    break;
            }
        }
        
        toggle() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        }
        
        open() {
            this.isOpen = true;
            this.trigger.setAttribute('aria-expanded', 'true');
            this.menu.classList.add('show');
            
            // Trigger custom event
            this.container.dispatchEvent(new CustomEvent('dropdown:open', {
                detail: { dropdown: this }
            }));
            
            // Scroll ke item yang dipilih
            const selectedItem = this.container.querySelector('.custom-dropdown-item.selected');
            if (selectedItem) {
                setTimeout(() => {
                    selectedItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }, 100);
            }
        }
        
        close() {
            this.isOpen = false;
            this.trigger.setAttribute('aria-expanded', 'false');
            this.menu.classList.remove('show');
            
            // Trigger custom event
            this.container.dispatchEvent(new CustomEvent('dropdown:close', {
                detail: { dropdown: this }
            }));
        }
        
        selectItem(item) {
            // Update semua item
            this.items.forEach(i => {
                i.classList.remove('selected');
                i.setAttribute('aria-selected', 'false');
                const checkIcon = i.querySelector('.selected-icon');
                if (checkIcon) checkIcon.remove();
            });
            
            item.classList.add('selected');
            item.setAttribute('aria-selected', 'true');
            
            // Tambah icon centang
            if (!item.querySelector('.selected-icon')) {
                const icon = document.createElement('i');
                icon.className = 'fas fa-check selected-icon';
                icon.setAttribute('aria-hidden', 'true');
                item.appendChild(icon);
            }
            
            // Update teks trigger
            const itemText = item.dataset.text || 
                           (item.querySelector('.item-nama')?.textContent + ' (' + item.dataset.tahun + ')');
            this.triggerText.textContent = itemText;
            
            // Update hidden input
            this.selectedValue = item.dataset.value;
            if (this.hiddenInput) {
                this.hiddenInput.value = this.selectedValue;
            }
            
            // Trigger custom event sebelum submit
            this.container.dispatchEvent(new CustomEvent('dropdown:select', {
                detail: { 
                    dropdown: this,
                    value: this.selectedValue,
                    text: itemText
                }
            }));
            
            // Submit form dengan loading state
            if (this.hiddenForm) {
                this.submitForm();
            }
            
            this.close();
        }
        
        submitForm() {
            // Tambah loading state
            this.dropdown.classList.add('loading');
            
            // Trigger event sebelum submit
            this.container.dispatchEvent(new CustomEvent('dropdown:beforesubmit', {
                detail: { dropdown: this }
            }));
            
            // Submit form
            this.hiddenForm.submit();
        }
        
        updateSelectedItem() {
            if (this.selectedValue) {
                const selectedItem = Array.from(this.items).find(
                    item => item.dataset.value === this.selectedValue
                );
                if (selectedItem && selectedItem.dataset.text) {
                    this.triggerText.textContent = selectedItem.dataset.text;
                }
            }
        }
        
        focusNextItem(currentIndex) {
            const items = Array.from(this.items);
            if (currentIndex < items.length - 1) {
                items[currentIndex + 1].focus();
            } else {
                items[0].focus();
            }
        }
        
        focusPrevItem(currentIndex) {
            const items = Array.from(this.items);
            if (currentIndex > 0) {
                items[currentIndex - 1].focus();
            } else {
                items[items.length - 1].focus();
            }
        }
        
        // Public method untuk mendapatkan nilai yang dipilih
        getValue() {
            return this.selectedValue;
        }
        
        // Public method untuk set nilai secara programatis
        setValue(value) {
            const item = Array.from(this.items).find(i => i.dataset.value === String(value));
            if (item) {
                this.selectItem(item);
            }
        }
        
        // Public method untuk refresh dropdown (jika ada perubahan data)
        refresh() {
            // Re-query items (berguna jika konten berubah)
            this.items = this.container.querySelectorAll('.custom-dropdown-item');
            this.updateSelectedItem();
        }
        
        // Destroy method untuk cleanup
        destroy() {
            // Remove event listeners jika perlu
            // Dalam implementasi sederhana, kita biarkan garbage collector bekerja
        }
    }
    
    // Inisialisasi semua custom dropdown
    function initDropdowns() {
        const containers = document.querySelectorAll('.custom-dropdown-container');
        
        if (containers.length === 0) {
            console.log('ℹ️ Tidak ada custom dropdown ditemukan di halaman kepengurusan');
            return;
        }
        
        // Simpan instance di window untuk akses global jika diperlukan
        window.__kepengurusanDropdowns = [];
        
        containers.forEach((container, index) => {
            try {
                const dropdown = new CustomDropdown(container);
                window.__kepengurusanDropdowns.push(dropdown);
                
                // Beri ID unik jika belum ada
                if (!container.id) {
                    container.id = `custom-dropdown-${index}`;
                }
                
                console.log(`  ✅ Dropdown ${index + 1} initialized`);
            } catch (error) {
                console.error(`  ❌ Gagal inisialisasi dropdown ${index + 1}:`, error);
            }
        });
        
        console.log(`✅ Total ${containers.length} custom dropdown berhasil diinisialisasi`);
    }
    
    // Jalankan inisialisasi
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDropdowns);
    } else {
        initDropdowns();
    }
    
    // Export instance untuk digunakan module lain
    return {
        getDropdowns: () => window.__kepengurusanDropdowns || [],
        getDropdownById: (id) => {
            const dropdowns = window.__kepengurusanDropdowns || [];
            return dropdowns.find(d => d.container.id === id);
        }
    };
}