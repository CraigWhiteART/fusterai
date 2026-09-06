-- Test database bootstrap. Kept separate from init.sql so the test stack never
-- shares state or grants with the development database.

-- pgvector, for the AI embedding columns.
CREATE EXTENSION IF NOT EXISTS vector;

-- Pest --parallel creates one database per worker process (fusterai_test_1, _2, …).
-- Without CREATEDB the parallel run fails on the first worker.
ALTER USER fusterai CREATEDB;
