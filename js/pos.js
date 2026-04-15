// Cart data
let cart = [];
let selectedCupSize = {}; // legacy placeholder (no longer used)

// POS JavaScript loaded
console.log('POS JavaScript loaded successfully!');

// Security: Get CSRF token from config
function getCSRFToken() {
    return window.POS_CONFIG?.csrfToken || '';
}

// Helper function to make secure fetch requests
async function secureFetch(url, options = {}) {
    const csrfToken = getCSRFToken();
    
    // Add CSRF token to headers
    options.headers = {
        ...options.headers,
        'X-CSRF-TOKEN': csrfToken
    };
    
    return fetch(url, options);
}

// Add product to cart
function addToCart(element, options = {}) {
    console.log('Adding product:', element.dataset); // Debug log
    
    const productId = element.dataset.id;
    const productCode = element.dataset.code;
    const productName = element.dataset.name;
    const productPrice = parseFloat(options.price ?? element.dataset.price);
    const productStock = parseInt(element.dataset.stock);

    const cupSize = options.cupSize ?? element.dataset.selectedCupSize ?? 'none';
    const cupIdRaw = options.cupId ?? element.dataset.selectedCupId ?? null;
    const cupId = cupIdRaw !== null && cupIdRaw !== undefined && cupIdRaw !== '' ? parseInt(cupIdRaw) : null;

    console.log('Product details:', { productId, productCode, productName, productPrice, productStock }); // Debug log
    
    if (productStock <= 0) {
        alert('Product is out of stock!');
        return;
    }
    
    // Create unique key for cart items (product + cup size)
    const cartKey = `${productId}:${cupId || 'none'}`;
    
    // Check if product already in cart
    const existingItem = cart.find(item => item.cartKey === cartKey);
    
    if (existingItem) {
        console.log('Product already in cart, updating quantity'); // Debug log
        if (existingItem.quantity < productStock) {
            existingItem.quantity++;
            existingItem.subtotal = existingItem.quantity * existingItem.price;
            console.log('Updated existing item:', existingItem); // Debug log
        } else {
            alert('Cannot add more. Insufficient stock!');
            return;
        }
    } else {
        console.log('Adding new product to cart'); // Debug log
        const newItem = {
            cartKey: cartKey,
            id: productId,
            code: productCode,
            name: productName,
            price: productPrice,
            quantity: 1,
            stock: productStock,
            subtotal: productPrice,
            cupSize: cupSize,
            cupId: cupId
        };
        console.log('New item created:', newItem); // Debug log
        cart.push(newItem);
    }
    
    console.log('Cart after adding:', cart); // Debug log
    updateCart();
}

// ============================================
// CUP SIZE SELECTION + PRODUCT CLICK HANDLERS
// ============================================

function parseCupSizesFromCard(card) {
    try {
        const raw = card?.dataset?.cupSizes;
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        console.warn('Failed to parse cup sizes:', e);
        return [];
    }
}

function applyCupSelection(card, cup) {
    if (!card || !cup) return;

    card.dataset.selectedCupId = String(cup.cup_id);
    card.dataset.selectedCupSize = String(cup.cup_size);
    card.dataset.selectedCupPrice = String(cup.price);
    card.dataset.price = String(cup.price);

    const priceEl = card.querySelector('.price');
    if (priceEl) {
        priceEl.textContent = `₱${Number(cup.price).toFixed(2)}`;
        priceEl.dataset.basePrice = String(cup.price);
    }
}

function selectCupSize(button, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const card = button.closest('.product-card');
    if (!card) return;

    // Update selected state styling
    const buttons = card.querySelectorAll('.cup-btn');
    buttons.forEach(b => b.classList.remove('selected'));
    button.classList.add('selected');

    const cup = {
        cup_id: parseInt(button.dataset.cupId),
        cup_size: button.dataset.cupSize,
        price: parseFloat(button.dataset.price)
    };
    applyCupSelection(card, cup);

    // Requirement: clicking a specific size should immediately add to cart
    addToCart(card, {
        cupId: cup.cup_id,
        cupSize: cup.cup_size,
        price: cup.price
    });
}

function handleProductClick(card, event) {
    if (event) {
        // If click originated from a cup button, let it handle itself
        if (event.target && event.target.closest && event.target.closest('.cup-btn')) {
            return;
        }
    }

    const isDrink = String(card.dataset.isDrink).toLowerCase() === 'true';
    const cupSizes = parseCupSizesFromCard(card);

    if (isDrink && cupSizes.length > 0) {
        alert('Please click a cup size (12oz/16oz) to add.');
        return;
    }

    // Non-drink or no cup sizes
    addToCart(card, { cupId: null, cupSize: 'none' });
}

// Update cart display
function updateCart() {
    const cartItemsDiv = document.getElementById('cartItems');
    
    if (cart.length === 0) {
        cartItemsDiv.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 40px 0;">Cart is empty</p>';
    } else {
        cartItemsDiv.innerHTML = cart.map((item, index) => {
            const displayName = item.cupSize && item.cupSize !== 'none' 
                ? `${item.name} (${item.cupSize})` 
                : item.name;
            return `
            <div class="cart-item" data-sale-item-id="${item.id || 'temp-' + index}" data-index="${index}">
                <div class="cart-item-details" style="flex: 1;">
                    <h4 style="margin: 0 0 4px 0; font-size: 13px;">${displayName}</h4>
                    <p style="margin: 0; font-size: 12px; color: #666;">₱${item.price.toFixed(2)} × ${item.quantity}</p>
                </div>
                <div class="cart-item-actions" style="display: flex; align-items: center; gap: 8px;">
                    <strong style="color: var(--primary); min-width: 70px; text-align: right;">₱${item.subtotal.toFixed(2)}</strong>
                    <div class="quantity-control" style="display: flex; align-items: center; gap: 4px;">
                        <button type="button" class="quantity-btn" onclick="decreaseQuantity(${index})" style="width: 26px; height: 26px; border: none; border-radius: 4px; background: #007bff; color: white; cursor: pointer; font-weight: bold; font-size: 14px;">-</button>
                        <span style="font-weight: 600; min-width: 24px; text-align: center; font-size: 12px;">${item.quantity}</span>
                        <button type="button" class="quantity-btn" onclick="increaseQuantity(${index})" style="width: 26px; height: 26px; border: none; border-radius: 4px; background: #007bff; color: white; cursor: pointer; font-weight: bold; font-size: 14px;">+</button>
                    </div>
                </div>
            </div>
        `}).join('');
    }
    
    updateTotals();
}

// Update quantity
function updateQuantity(index, newQuantity) {
    const qty = parseInt(newQuantity);
    
    if (isNaN(qty) || qty < 1) {
        alert('Quantity must be at least 1');
        updateCart();
        return;
    }
    
    if (qty > cart[index].stock) {
        alert('Cannot add more. Insufficient stock!');
        updateCart();
        return;
    }
    
    cart[index].quantity = qty;
    cart[index].subtotal = cart[index].quantity * cart[index].price;
    updateCart();
}

// Increase quantity
function increaseQuantity(index) {
    if (cart[index].quantity < cart[index].stock) {
        cart[index].quantity++;
        cart[index].subtotal = cart[index].quantity * cart[index].price;
        updateCart();
    } else {
        alert('Cannot add more. Insufficient stock!');
    }
}

// Decrease quantity
function decreaseQuantity(index) {
    if (cart[index].quantity > 1) {
        cart[index].quantity--;
        cart[index].subtotal = cart[index].quantity * cart[index].price;
        updateCart();
    }
}

// Remove from cart
function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
}



// Return cart (clear all items)
function returnCart() {
    if (cart.length === 0) {
        alert('Cart is already empty!');
        return;
    }
    
    if (confirm('Remove all items from cart? This action cannot be undone.')) {
        cart = [];
        selectedCupSize = {};
        updateCart();
        alert('✓ Cart cleared successfully!');
    }
}

// Update totals
function updateTotals() {
    console.log('=== UPDATE TOTALS CALLED ==='); // Debug log
    console.log('Current cart:', cart); // Debug log
    console.log('Cart length:', cart.length); // Debug log
    
    if (!cart || cart.length === 0) {
        console.log('Cart is empty, setting totals to 0'); // Debug log
        document.getElementById('subtotal').textContent = '₱0.00';
        document.getElementById('tax').textContent = '₱0.00';
        document.getElementById('discount').textContent = '₱0.00';
        document.getElementById('grandTotal').textContent = '₱0.00';
        return;
    }
    
    let subtotal = 0;
    for (let i = 0; i < cart.length; i++) {
        console.log(`Item ${i}:`, cart[i]); // Debug log
        console.log(`Item ${i} subtotal:`, cart[i].subtotal); // Debug log
        subtotal += parseFloat(cart[i].subtotal) || 0;
    }
    
    console.log('Calculated subtotal:', subtotal); // Debug log
    
    const tax = subtotal * 0.12; // 12% tax
    const total = subtotal + tax;
    
    console.log('Final totals:', { subtotal, tax, total }); // Debug log
    
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('tax').textContent = '₱' + tax.toFixed(2);
    document.getElementById('discount').textContent = '₱0.00';
    document.getElementById('grandTotal').textContent = '₱' + total.toFixed(2);
}

// Open checkout modal
function openCheckout() {
    if (cart.length === 0) {
        alert('Cart is empty!');
        return;
    }
    
    // Display cart items in modal
    displayModalCart();
    updateModalTotals();
    
    // Reset form
    document.getElementById('customerName').value = '';
    document.getElementById('discountAmount').value = '0';
    document.getElementById('amountPaid').value = '';
    document.getElementById('changeAmount').value = '';
    
    // Show modal
    document.getElementById('checkoutModal').classList.add('active');
}

// Close checkout modal
function closeCheckout() {
    document.getElementById('checkoutModal').classList.remove('active');
}

// ============================================
// CHECKOUT MODAL HELPERS
// ============================================

function displayModalCart() {
    const modalCartItems = document.getElementById('modalCartItems');
    if (!modalCartItems) return;

    const activeItems = cart.filter(item => !item.voided);

    if (activeItems.length === 0) {
        modalCartItems.innerHTML = '<p style="text-align:center;color:var(--text-secondary);padding:16px 0;">No items.</p>';
        return;
    }

    modalCartItems.innerHTML = activeItems.map(item => {
        const name = item.cupSize && item.cupSize !== 'none' ? `${item.name} (${item.cupSize})` : item.name;
        return `
            <div style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);">
                <div style="flex:1;">
                    <strong style="font-size:13px;">${name}</strong><br>
                    <span style="font-size:12px;color:var(--text-secondary);">₱${item.price.toFixed(2)} × ${item.quantity}</span>
                </div>
                <div style="min-width:90px;text-align:right;font-weight:700;">₱${item.subtotal.toFixed(2)}</div>
            </div>
        `;
    }).join('');
}

function updateModalTotals() {
    const activeItems = cart.filter(item => !item.voided);
    const subtotal = activeItems.reduce((sum, item) => sum + (parseFloat(item.subtotal) || 0), 0);
    const tax = subtotal * 0.12;
    const discount = parseFloat(document.getElementById('discountAmount')?.value || 0) || 0;
    const total = Math.max(0, subtotal + tax - discount);

    const modalSubtotal = document.getElementById('modalSubtotal');
    const modalTax = document.getElementById('modalTax');
    const modalDiscount = document.getElementById('modalDiscount');
    const modalGrandTotal = document.getElementById('modalGrandTotal');

    if (modalSubtotal) modalSubtotal.textContent = '₱' + subtotal.toFixed(2);
    if (modalTax) modalTax.textContent = '₱' + tax.toFixed(2);
    if (modalDiscount) modalDiscount.textContent = '₱' + discount.toFixed(2);
    if (modalGrandTotal) modalGrandTotal.textContent = '₱' + total.toFixed(2);

    calculateModalChange();
}

function calculateModalChange() {
    const paid = parseFloat(document.getElementById('amountPaid')?.value || 0) || 0;
    const grandTotalText = document.getElementById('modalGrandTotal')?.textContent || '₱0.00';
    const total = parseFloat(grandTotalText.replace(/[₱,\s]/g, '')) || 0;
    const change = Math.max(0, paid - total);

    const changeEl = document.getElementById('changeAmount');
    if (changeEl) changeEl.value = change.toFixed(2);
}

document.getElementById('amountPaid')?.addEventListener('input', calculateModalChange);

// Update discount in modal
document.getElementById('discountAmount')?.addEventListener('input', updateModalTotals);

// Handle checkout form submission
document.getElementById('checkoutForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    console.log('Checkout form submitted'); // Debug log
    
    // Filter out voided items
    const activeItems = cart.filter(item => !item.voided);
    
    if (activeItems.length === 0) {
        alert('No items to checkout. All items have been voided.');
        return;
    }
    
    const subtotal = activeItems.reduce((sum, item) => sum + item.subtotal, 0);
    const tax = subtotal * 0.12;
    const discount = parseFloat(document.getElementById('discountAmount').value || 0);
    const total = subtotal + tax - discount;
    const paid = parseFloat(document.getElementById('amountPaid').value);
    
    console.log('Calculations:', { subtotal, tax, discount, total, paid }); // Debug log
    
    if (paid < total) {
        alert('Amount paid is less than total!');
        return;
    }
    
    // Prepare sale data for API
    const saleData = {
        customer_name: document.getElementById('customerName').value,
        payment_method: document.getElementById('paymentMethod').value,
        subtotal: subtotal,
        tax: tax,
        discount: discount,
        total: total,
        amount_paid: paid,
        change: paid - total,
        items: activeItems.map(item => ({
            id: item.id,
            name: item.name,
            price: item.price,
            quantity: item.quantity,
            subtotal: item.subtotal,
            cup_size: item.cupSize || 'none',
            cup_id: item.cupId || null
        }))
    };
    
    console.log('Sale data being sent:', saleData); // Debug log
    
    try {
        const response = await secureFetch('api/process-sale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(saleData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Sale completed successfully!\nInvoice: ' + result.invoice);
            cart = [];
            selectedCupSize = {}; // Also reset cup size selections
            updateCart();
            closeCheckout();

            // After clicking OK on the alert, show receipt in the same tab
            const receiptUrl = 'receipt.php?invoice=' + encodeURIComponent(result.invoice);
            window.location.href = receiptUrl;
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error processing sale: ' + error.message);
    }
});

// Search products
document.getElementById('searchProduct')?.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const products = document.querySelectorAll('.product-card');
    
    products.forEach(product => {
        const name = product.dataset.name.toLowerCase();
        const code = product.dataset.code.toLowerCase();
        
        if (name.includes(searchTerm) || code.includes(searchTerm)) {
            product.style.display = 'block';
        } else {
            product.style.display = 'none';
        }
    });
});

// Filter by category
document.getElementById('filterCategory')?.addEventListener('change', function() {
    const categoryId = this.value;
    const products = document.querySelectorAll('.product-card');
    
    products.forEach(product => {
        if (categoryId === '' || product.dataset.category === categoryId) {
            product.style.display = 'block';
        } else {
            product.style.display = 'none';
        }
    });
});

// Close modal on outside click
window.addEventListener('click', function(e) {
    const checkoutModal = document.getElementById('checkoutModal');
    
    if (e.target === checkoutModal) {
        closeCheckout();
    }
});

// Make all functions globally accessible
window.returnCart = returnCart;
window.openCheckout = openCheckout;
window.closeCheckout = closeCheckout;
window.updateCart = updateCart;

// ============================================
// CART BUTTON EVENT LISTENERS
// ============================================
(function initCartButtons() {
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupCartButtons);
    } else {
        setupCartButtons();
    }
    
    function setupCartButtons() {
        // Return Cart Button
        const returnBtn = document.getElementById('returnCartBtn');
        if (returnBtn) {
            returnBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                returnCart();
            });
        }
        
        // Complete Sale Button
        const completeBtn = document.getElementById('completeSaleBtn');
        if (completeBtn) {
            completeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openCheckout();
            });
        }
        
        console.log('Cart buttons initialized');
    }
})();
