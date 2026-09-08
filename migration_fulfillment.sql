-- Add fulfillment-related columns: assigned_picker_id on orders, measured_quantity on order_items
ALTER TABLE orders
  ADD COLUMN assigned_picker_id INT DEFAULT NULL;

ALTER TABLE order_items
  ADD COLUMN measured_quantity DECIMAL(10,3) DEFAULT NULL;