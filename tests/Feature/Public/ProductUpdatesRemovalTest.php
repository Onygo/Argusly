<?php it('returns 404 for removed product-updates page', function () { $this->get('/product-updates')->assertNotFound(); });
