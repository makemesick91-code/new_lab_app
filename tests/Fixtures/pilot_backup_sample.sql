--
-- Minimal pilot backup fixture for RME import tests
--

COPY public.mst_branches (id, code, name, address, phone, is_active, created_at, updated_at, deleted_at) FROM stdin;
99	PILOT-BR	Pilot Branch Jl. Test	Alamat pilot	081234567890	t	2026-06-08 07:31:48	2026-06-08 07:31:48	\N
\.

COPY public.roles (id, name, guard_name, created_at, updated_at) FROM stdin;
1	admin	web	2026-06-08 07:31:48	2026-06-08 07:31:48
\.

COPY public.mst_doctors (id, clinic_id, code, name, phone, email, is_active, created_at, updated_at, deleted_at) FROM stdin;
501	1	DOC-PILOT01	Dr. Pilot Satu	081111111111	pilot1@example.test	t	2026-06-08 07:31:48	2026-06-08 07:31:48	\N
502	1	DOC-PILOT02	Dr. Pilot Dua	082222222222	pilot2@example.test	t	2026-06-08 07:31:48	2026-06-08 07:31:48	\N
\.

COPY public.mst_patients (id, clinic_id, doctor_id, medical_record_number, name, gender, date_of_birth, phone, address, is_active, created_at, updated_at, deleted_at) FROM stdin;
601	1	501	MRN-PILOT001	Pasien Pilot Alpha	Male	1990-01-15	083333333333	Jl. Alpha No. 1	t	2026-06-08 07:31:48	2026-06-08 07:31:48	\N
602	1	502	MRN-PILOT002	Pasien Pilot Beta	Female	1985-06-20	084444444444	Jl. Beta No. 2	t	2026-06-08 07:31:48	2026-06-08 07:31:48	\N
\.

COPY public.mst_lab_services (id, code, name, category, description, turnaround_days, price, is_active, created_at, updated_at, deleted_at) FROM stdin;
701	SVC-PILOT01	Scaling & Polishing	General	Pembersihan karang gigi	1	250000.00	t	2026-06-08 07:31:48	2026-06-08 07:31:48	\N
702	SVC-PILOT02	Crown Zirconia	Fixed	Lab crown service	7	3500000.00	t	2026-06-08 07:31:48	2026-06-08 07:31:48	\N
\.

COPY public.trx_payments (id, branch_id, invoice_id, amount, paid_at, created_at, updated_at) FROM stdin;
9001	1	8001	100000.00	2026-06-08 07:31:48	2026-06-08 07:31:48	2026-06-08 07:31:48
\.
