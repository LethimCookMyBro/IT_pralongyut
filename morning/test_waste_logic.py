import unittest
from waste_logic import proper_disposal_rate, validate_report


class RateTests(unittest.TestCase):
    def test_normal_and_boundary(self):
        cases = [
            (100, 87.26, 87.3),
            (100, 0, 0.0),
            (100, 100, 100.0),
            (3.5, 1.75, 50.0),
        ]
        for gen, proper, expected in cases:
            with self.subTest(gen=gen, proper=proper):
                self.assertEqual(proper_disposal_rate(gen, proper), expected)

    def test_invalid(self):
        for gen, proper in [(0, 0), (-1, 0), (100, -1), (100, 101),
                            ("100", 50), (100, True), (None, 1)]:
            with self.subTest(gen=gen, proper=proper):
                with self.assertRaises(ValueError):
                    proper_disposal_rate(gen, proper)


class ValidateReportTests(unittest.TestCase):
    def good(self, **over):
        base = {"location": " หาดบางแสน หน้าโค้งวงเวียน ",
                "waste_type": "general", "amount_kg": "20", "detail": ""}
        base.update(over)
        return base

    def test_clean_output(self):
        result = validate_report(self.good())
        self.assertEqual(result, {
            "location": "หาดบางแสน หน้าโค้งวงเวียน",
            "waste_type": "general",
            "amount_kg": 20,
            "detail": "",
        })
        self.assertIs(type(result["amount_kg"]), int)

    def test_boundaries(self):
        self.assertEqual(validate_report(self.good(amount_kg=1))["amount_kg"], 1)
        self.assertEqual(validate_report(self.good(amount_kg=1000))["amount_kg"], 1000)
        self.assertEqual(validate_report(self.good(location="abc"))["location"], "abc")
        self.assertEqual(validate_report(self.good(detail=None)).get("detail"), "")

    def test_invalid_fields(self):
        bad_cases = {
            "location": ["", "  ", "ab", "x" * 101],
            "waste_type": ["plastic", "", None, "General"],
            "amount_kg": [0, 1001, -5, "20.5", "abc", True, 20.5, None],
            "detail": ["x" * 501],
        }
        for field, values in bad_cases.items():
            for value in values:
                with self.subTest(field=field, value=value):
                    with self.assertRaises(ValueError) as ctx:
                        validate_report(self.good(**{field: value}))
                    self.assertTrue(str(ctx.exception).startswith(field))


if __name__ == "__main__":
    unittest.main()
