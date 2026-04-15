<?php
$user = function_exists('getCurrentUser') ? getCurrentUser() : [];
$is_cashier = (($user['role'] ?? null) === 'cashier');
$is_admin = function_exists('isAdmin') ? isAdmin() : false;
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>

<aside class="sidebar<?php echo $is_cashier ? ' sidebar-hidden' : ''; ?>">
    <div class="sidebar-brand">
        <h2>
            <span class="icon icon-logo">
                <img src="pictures/poprie.jpg" alt="POPRIE Logo">
            </span>
            POPRIE
        </h2>
    </div>

    <ul class="sidebar-menu">
        <?php if (!$is_cashier): ?>
        <li>
            <a href="index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                <span class="icon">📊</span>
                Dashboard
            </a>
        </li>
        <?php endif; ?>

        <?php if ($is_cashier): ?>
        <li>
            <a href="pos.php" class="<?php echo $currentPage === 'pos.php' ? 'active' : ''; ?>">
                <span class="icon">💳</span>
                Point of Sale
            </a>
        </li>
        <li>
            <a href="sales.php" class="<?php echo $currentPage === 'sales.php' ? 'active' : ''; ?>">
                <span class="icon">💰</span>
                Sales History
            </a>
        </li>
        <?php endif; ?>

        <?php if (!$is_cashier): ?>
        <li>
            <a href="inventory.php" class="<?php echo $currentPage === 'inventory.php' ? 'active' : ''; ?>" onclick="toggleInventoryDropdown(event)">
                <span class="icon">📦</span>
                Inventory
                <span class="dropdown-arrow" id="inventory-arrow">▼</span>
            </a>
            <ul class="submenu" id="inventory-submenu">
                <li>
                    <a href="inventory.php" class="<?php echo $currentPage === 'inventory.php' ? 'active' : ''; ?>">
                        <span class="icon">📊</span>
                        Product Inventory
                    </a>
                </li>
                <li>
                    <a href="cup_inventory.php" class="<?php echo $currentPage === 'cup_inventory.php' ? 'active' : ''; ?>">
                        <span class="icon">🥤</span>
                        Cup Inventory
                    </a>
                </li>
                <li>
                    <a href="ingredients.php" class="<?php echo $currentPage === 'ingredients.php' ? 'active' : ''; ?>">
                        <span class="icon">🧪</span>
                        Ingredients
                    </a>
                </li>
                <li>
                    <a href="inventory.php#stock-movements" class="<?php echo $currentPage === 'inventory.php' ? 'active' : ''; ?>">
                        <span class="icon">📈</span>
                        Stock Movements
                    </a>
                </li>
            </ul>
        </li>

        <li>
            <a href="products.php" class="<?php echo $currentPage === 'products.php' ? 'active' : ''; ?>">
                <span class="icon">🏷️</span>
                Products
            </a>
        </li>

        <li>
            <a href="sales.php" class="<?php echo $currentPage === 'sales.php' ? 'active' : ''; ?>">
                <span class="icon">💰</span>
                Sales History
            </a>
        </li>
        <?php endif; ?>

        <?php if ($is_admin): ?>
        <li>
            <a href="categories.php" class="<?php echo $currentPage === 'categories.php' ? 'active' : ''; ?>">
                <span class="icon">📑</span>
                Categories
            </a>
        </li>
        <li>
            <a href="reports.php" class="<?php echo $currentPage === 'reports.php' ? 'active' : ''; ?>">
                <span class="icon">📈</span>
                Reports
            </a>
        </li>
        <li>
            <a href="users.php" class="<?php echo $currentPage === 'users.php' ? 'active' : ''; ?>">
                <span class="icon">👥</span>
                Users
            </a>
        </li>
        <li>
            <a href="voids.php" class="<?php echo $currentPage === 'voids.php' ? 'active' : ''; ?>">
                <span class="icon">❌</span>
                Voided Sales
            </a>
        </li>
        <li>
            <a href="activity_logs.php" class="<?php echo $currentPage === 'activity_logs.php' ? 'active' : ''; ?>">
                <span class="icon">📋</span>
                Activity Logs
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <script src="js/inventory-dropdown.js"></script>
</aside>