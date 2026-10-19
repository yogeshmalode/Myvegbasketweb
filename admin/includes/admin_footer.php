<?php if (isset($orderLiveRefreshScope, $orderLiveRefreshVersion)): ?>
<div id="orderLiveRefresh" hidden
     data-scope="<?= h($orderLiveRefreshScope) ?>"
     data-version="<?= h($orderLiveRefreshVersion) ?>"
     <?= isset($orderLiveRefreshPage) ? 'data-page="' . h($orderLiveRefreshPage) . '"' : '' ?>></div>
<script src="<?= BASE_URL ?>/assets/js/order-live-refresh.js?v=<?= (int)filemtime(__DIR__ . '/../../assets/js/order-live-refresh.js') ?>"></script>
<?php endif; ?>
</div></main>
</body>
</html>
