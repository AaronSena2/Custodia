-- Custodia — upgrade script 011: client bio fields (email/phone/address).
--
-- Run this ONCE against an existing `custodia` database that predates these
-- columns. Adds email/phone/address to clients, shown on the new client
-- profile page (client.php) and editable from the Add/Edit Client forms.
--
-- A brand-new install does NOT need this file — sql/schema.sql already
-- includes everything below.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN email   VARCHAR(255) NULL AFTER name,
  ADD COLUMN phone   VARCHAR(50)  NULL AFTER email,
  ADD COLUMN address TEXT         NULL AFTER phone;
